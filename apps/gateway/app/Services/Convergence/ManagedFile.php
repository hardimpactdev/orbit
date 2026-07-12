<?php

declare(strict_types=1);

namespace App\Services\Convergence;

use App\Data\Convergence\ConvergenceApplyResult;
use App\Data\Convergence\ManagedFilePlan;
use App\Data\Convergence\ManagedFileProbe;
use App\Enums\Convergence\ConvergenceStatus;
use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\RemoteShell\RemoteShellSuccessData;
use App\Services\RemoteShell\RunsInternalCommands;
use InvalidArgumentException;
use JsonException;

final readonly class ManagedFile
{
    /** @mago-expect lint:excessive-parameter-list */
    public function __construct(
        public string $path,
        public string $content,
        public string $mode = '0644',
        public string $directoryMode = '0755',
        public bool $sensitive = false,
        private ?RunsInternalCommands $localExecutor = null,
    ) {
        $this->ensureAbsolutePath($path);
        $this->ensureMode($mode, 'mode');
        $this->ensureMode($directoryMode, 'directory mode');
    }

    /**
     * @param  array<string, mixed>  $intent
     */
    public static function fromIntent(
        array $intent,
        string $defaultMode = '0644',
        string $defaultDirectoryMode = '0755',
        bool $sensitive = false,
        ?RunsInternalCommands $localExecutor = null,
    ): self {
        $path = $intent['path'] ?? null;
        $declaredHash = $intent['hash'] ?? null;
        $content = $intent['content'] ?? null;
        $mode = $intent['mode'] ?? $defaultMode;
        $directoryMode = $intent['directory_mode'] ?? $defaultDirectoryMode;

        if (! is_string($path) || trim($path) === '') {
            throw new InvalidArgumentException('Managed file path is required.');
        }

        if (! is_string($declaredHash) || trim($declaredHash) === '') {
            throw new InvalidArgumentException('Managed file hash is required.');
        }

        $declaredHash = strtolower(trim($declaredHash));

        if (preg_match('/^[a-f0-9]{64}$/', $declaredHash) !== 1) {
            throw new InvalidArgumentException('Managed file hash must be a SHA-256 hex digest.');
        }

        if (! is_string($content)) {
            throw new InvalidArgumentException('Managed file content is required.');
        }

        if (! is_string($mode) || trim($mode) === '') {
            throw new InvalidArgumentException('Managed file mode is required.');
        }

        if (! is_string($directoryMode) || trim($directoryMode) === '') {
            throw new InvalidArgumentException('Managed file directory mode is required.');
        }

        $file = new self(
            path: $path,
            content: $content,
            mode: $mode,
            directoryMode: $directoryMode,
            sensitive: $sensitive,
            localExecutor: $localExecutor,
        );

        if (! hash_equals($declaredHash, $file->hash())) {
            throw new InvalidArgumentException('Managed file content hash does not match declared hash.');
        }

        return $file;
    }

    public function probe(Node $node): ManagedFileProbe
    {
        $result = $this->localExecutor()->runInternal(
            node: $node,
            commandName: 'internal:managed-file',
            arguments: ['probe'],
            transportOptions: [
                'input' => json_encode(['path' => $this->path], JSON_THROW_ON_ERROR),
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'managed-file.probe',
                ],
                'redact_stdout' => $this->sensitive,
                'redact_stderr' => $this->sensitive,
                'timeout' => 30,
                'throw' => false,
            ],
        );

        if (! $result->successful()) {
            return new ManagedFileProbe(
                reachable: false,
                exists: false,
                error: trim($result->stderr) !== ''
                    ? trim($result->stderr)
                    : "Probe exited with code {$result->exitCode}.",
            );
        }

        try {
            $payload = RemoteShellSuccessData::fromJsonEnvelope($result);
        } catch (JsonException $exception) {
            return new ManagedFileProbe(
                reachable: false,
                exists: false,
                error: "Probe returned invalid JSON: {$exception->getMessage()}",
            );
        }

        return new ManagedFileProbe(
            reachable: true,
            exists: ($payload['exists'] ?? null) === true,
            hash: is_string($payload['hash'] ?? null) ? $payload['hash'] : null,
            mode: is_string($payload['mode'] ?? null) ? $payload['mode'] : null,
        );
    }

    public function plan(ManagedFileProbe $probe): ManagedFilePlan
    {
        if (! $probe->reachable) {
            return new ManagedFilePlan(
                status: ConvergenceStatus::Unreachable,
                summary: "Could not inspect managed file {$this->path}.",
                details: $this->details(['error' => $probe->error]),
            );
        }

        if (! $probe->exists) {
            return new ManagedFilePlan(
                status: ConvergenceStatus::Changed,
                summary: "Create managed file {$this->path}.",
                details: $this->details(['observed_hash' => null]),
            );
        }

        if (! hash_equals($this->hash(), $probe->hash ?? '')) {
            return new ManagedFilePlan(
                status: ConvergenceStatus::Changed,
                summary: "Update managed file {$this->path}.",
                details: $this->details(['observed_hash' => $probe->hash]),
            );
        }

        if (! $this->modesMatch($probe->mode)) {
            return new ManagedFilePlan(
                status: ConvergenceStatus::Changed,
                summary: "Update managed file {$this->path} mode.",
                details: $this->details([
                    'observed_hash' => $probe->hash,
                    'observed_mode' => $probe->mode,
                ]),
            );
        }

        return new ManagedFilePlan(
            status: ConvergenceStatus::Ok,
            summary: "Managed file {$this->path} already matches gateway intent.",
            details: $this->details(['observed_hash' => $probe->hash]),
        );
    }

    public function apply(Node $node, ManagedFilePlan $plan): ConvergenceApplyResult
    {
        if (! $plan->shouldApply()) {
            return new ConvergenceApplyResult(
                status: $plan->status,
                summary: $plan->summary,
                details: $plan->details,
            );
        }

        $result = $this->localExecutor()->runInternal(
            node: $node,
            commandName: 'internal:managed-file',
            arguments: ['write'],
            transportOptions: [
                'input' => json_encode([
                    'path' => $this->path,
                    'content' => $this->content,
                    'mode' => $this->mode,
                    'directory_mode' => $this->directoryMode,
                ], JSON_THROW_ON_ERROR),
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'managed-file.write',
                ],
                'redact_stdout' => $this->sensitive,
                'redact_stderr' => $this->sensitive,
                'timeout' => 30,
                'throw' => false,
            ],
        );

        if (! $result->successful()) {
            return new ConvergenceApplyResult(
                status: ConvergenceStatus::Failed,
                summary: "Failed to apply managed file {$this->path}.",
                details: $this->details([
                    'exit_code' => $result->exitCode,
                    'error' => trim($result->stderr) !== '' ? trim($result->stderr) : null,
                ]),
            );
        }

        return new ConvergenceApplyResult(
            status: ConvergenceStatus::Changed,
            summary: "Applied managed file {$this->path}.",
            details: $this->details(),
        );
    }

    public function writeScript(): string
    {
        return sprintf(
            <<<'SH'
                set -euo pipefail
                sudo install -d -m %s %s
                printf %%s %s | base64 -d | sudo tee %s >/dev/null
                sudo chmod %s %s
                SH,
            $this->directoryMode,
            escapeshellarg(dirname($this->path)),
            escapeshellarg(base64_encode($this->content)),
            escapeshellarg($this->path),
            $this->mode,
            escapeshellarg($this->path),
        );
    }

    private function localExecutor(): RunsInternalCommands
    {
        return $this->localExecutor ?? app(RemoteLocalExecutor::class);
    }

    public function hash(): string
    {
        return hash('sha256', $this->content);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function details(array $extra = []): array
    {
        return [
            'path' => $this->path,
            'mode' => $this->mode,
            'directory_mode' => $this->directoryMode,
            'sensitive' => $this->sensitive,
            'expected_hash' => $this->hash(),
            ...array_filter($extra, fn (mixed $value): bool => $value !== null),
        ];
    }

    private function modesMatch(?string $observed): bool
    {
        if ($observed === null || trim($observed) === '') {
            return true;
        }

        return $this->normalizeMode($observed) === $this->normalizeMode($this->mode);
    }

    private function normalizeMode(string $mode): string
    {
        $mode = ltrim(trim($mode), '0');

        return str_pad($mode !== '' ? $mode : '0', 4, '0', STR_PAD_LEFT);
    }

    private function ensureAbsolutePath(string $path): void
    {
        if (str_starts_with($path, '/')) {
            return;
        }

        throw new InvalidArgumentException('Managed file path must be absolute.');
    }

    private function ensureMode(string $mode, string $label): void
    {
        if (preg_match('/^[0-7]{3,4}$/', $mode) === 1) {
            return;
        }

        throw new InvalidArgumentException("Managed file {$label} must be an octal permission string.");
    }
}

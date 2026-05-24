<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Data\RemoteShell\RemoteShellResult;
use App\Exceptions\RemoteShellFailed;
use App\Models\Node;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

final readonly class RemoteOrbitRuntimeExecutor implements RemoteExecutor
{
    private const int DEFAULT_TIMEOUT = 120;

    private const string CONTAINER = 'orbit-runtime';

    public function __construct(
        private RemoteShellScriptComposer $scripts,
        private SshCommandBuilder $ssh,
        private NodeRoleAssignments $roleAssignments,
        private RemoteShellAuditLogger $auditLogger,
    ) {}

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }  $options
     */
    #[\Override]
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $runtimeScript = $this->runtimeScript($script, $options);
        $command = $this->command($node, $runtimeScript);

        $startedAt = hrtime(true);
        $processResult = $this->pendingProcess($options)->run($command);
        $durationMs = (int) ((hrtime(true) - $startedAt) / 1_000_000);

        $result = new RemoteShellResult(
            exitCode: $processResult->exitCode() ?? 1,
            stdout: $processResult->output(),
            stderr: $processResult->errorOutput(),
            durationMs: $durationMs,
        );

        $this->auditLogger->log('remote_shell.run', $node, $script, $options, $result);

        if ((bool) ($options['throw'] ?? false) && ! $result->successful()) {
            throw new RemoteShellFailed($node, $runtimeScript, $result);
        }

        return $result;
    }

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }  $options
     */
    #[\Override]
    public function start(Node $node, string $script, array $options = []): InvokedProcess
    {
        $process = $this->pendingProcess($options)->start(
            $this->command($node, $this->runtimeScript($script, $options)),
        );

        $this->auditLogger->log('remote_shell.start', $node, $script, $options);

        return $process;
    }

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }  $options
     */
    private function pendingProcess(array $options): PendingProcess
    {
        $pendingProcess = Process::timeout((int) ($options['timeout'] ?? self::DEFAULT_TIMEOUT));

        if (array_key_exists('input', $options)) {
            return $pendingProcess->input((string) $options['input']);
        }

        return $pendingProcess;
    }

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }  $options
     */
    private function runtimeScript(string $script, array $options): string
    {
        $directCommand = $this->directRuntimeCommand($script);

        if ($directCommand !== null && ! (bool) ($options['strict'] ?? false)) {
            return $this->directDockerExec($directCommand, $options);
        }

        return implode(' ', [
            'docker exec -i',
            self::CONTAINER,
            'sh -lc',
            escapeshellarg($this->scripts->compose($script, $options)),
        ]);
    }

    /**
     * @param  array{
     *     cwd?: string,
     *     timeout?: int,
     *     input?: string,
     *     throw?: bool,
     *     metadata?: array<string, string>,
     *     strict?: bool,
     * }  $options
     */
    private function directDockerExec(string $runtimeCommand, array $options): string
    {
        $parts = ['docker exec -i'];

        foreach ($this->scripts->metadataFromOptions($options, validate: true) as $key => $value) {
            $parts[] = '--env '.escapeshellarg("{$key}={$value}");
        }

        if (isset($options['cwd']) && $options['cwd'] !== '') {
            $parts[] = '--workdir '.escapeshellarg($options['cwd']);
        }

        $parts[] = self::CONTAINER;
        $parts[] = $runtimeCommand;

        return implode(' ', $parts);
    }

    private function directRuntimeCommand(string $script): ?string
    {
        $command = $this->normalizeWhitespace($script);

        if ($command === '') {
            return null;
        }

        if ($command === 'artisan') {
            return 'php artisan';
        }

        if (str_starts_with($command, 'artisan ')) {
            return $this->safeDirectCommand('php artisan '.substr($command, strlen('artisan ')));
        }

        if ($command === 'php artisan' || str_starts_with($command, 'php artisan ')) {
            return $this->safeDirectCommand($command);
        }

        return $this->safeDirectCommand($command);
    }

    private function safeDirectCommand(string $command): ?string
    {
        if (preg_match('/\A[A-Za-z0-9_\/.:-]+(?: [A-Za-z0-9_\/.=:,@%+-]+)*\z/', $command) === 1) {
            return $command;
        }

        return null;
    }

    private function normalizeWhitespace(string $script): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $script));
    }

    private function command(Node $node, string $script): string
    {
        if ((bool) config('orbit.is_gateway', false) && $this->roleAssignments->nodeIsGateway($node)) {
            return 'bash -c '.escapeshellarg($script);
        }

        return $this->ssh->enforceForNode(
            node: $node,
            remoteCommand: 'bash -lc '.escapeshellarg($script),
            options: [
                'log_level' => 'ERROR',
                'server_alive_interval' => 30,
                'server_alive_count_max' => 10,
            ],
        );
    }
}

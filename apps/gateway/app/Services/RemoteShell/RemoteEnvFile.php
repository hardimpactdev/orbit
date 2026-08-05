<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use RuntimeException;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class RemoteEnvFile
{
    public function __construct(
        private RunsInternalCommands $localExecutor,
    ) {}

    /**
     * Legacy generic read used by initializer, database, doctor, and app paths.
     * Failures and malformed success envelopes collapse to null so existing
     * callers that treat null as "absent/empty" keep their prior behavior.
     */
    public function read(Node $node, string $path): ?string
    {
        $result = $this->run($node, [
            'action' => 'read',
            'path' => $path,
        ]);

        if (! $result->successful()) {
            return null;
        }

        $contents = $this->data($result)['contents'] ?? null;

        return is_string($contents) ? $contents : null;
    }

    /**
     * Strict read for workspace env apply only.
     *
     * Returns null solely for explicit `env_file.not_found`. Other failures and
     * successful envelopes without a string `contents` field throw so apply
     * cannot clobber an unread file by treating a protocol error as empty.
     */
    public function readForApply(Node $node, string $path): ?string
    {
        $result = $this->run($node, [
            'action' => 'read',
            'path' => $path,
        ]);

        if ($result->successful()) {
            $contents = $this->data($result)['contents'] ?? null;

            if (! is_string($contents)) {
                throw new RuntimeException(
                    "Env file read response for {$path} is missing string contents.",
                );
            }

            return $contents;
        }

        $error = RemoteShellSuccessData::errorFromJsonEnvelope($result);
        $code = $error['code'] ?? null;

        if ($code === 'env_file.not_found') {
            return null;
        }

        throw new RuntimeException($this->failureMessage($result, $error, "Failed to read env file at {$path}."));
    }

    public function write(
        Node $node,
        string $path,
        string $contents,
        ?string $runtimeUser = null,
    ): void {
        $payload = [
            'action' => 'write',
            'path' => $path,
            'contents' => $contents,
        ];

        if ($runtimeUser !== null) {
            $payload['runtime_user'] = $runtimeUser;
        }

        $result = $this->run($node, $payload);

        if (! $result->successful()) {
            $error = RemoteShellSuccessData::errorFromJsonEnvelope($result);

            throw new RuntimeException($this->failureMessage($result, $error, "Failed to write env file at {$path}."));
        }
    }

    /**
     * @param  array<string, mixed>  $error
     */
    private function failureMessage(RemoteShellResult $result, array $error, string $fallback): string
    {
        $message = $error['message'] ?? null;

        if (is_string($message) && trim($message) !== '') {
            return $message;
        }

        $output = trim($result->errorOutput().' '.$result->stdout);

        return $output !== '' ? $output : $fallback;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function run(Node $node, array $payload): RemoteShellResult
    {
        $action = is_string($payload['action'] ?? null) ? $payload['action'] : 'unknown';

        return $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:env-file',
            transportOptions: [
                'input' => json_encode($payload, JSON_THROW_ON_ERROR),
                'metadata' => [
                    'ORBIT_OPERATION_ID' => "env-file.{$action}",
                ],
                'redact_stderr' => true,
                'redact_stdout' => true,
                'strict' => true,
                'timeout' => 30,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function data(RemoteShellResult $result): array
    {
        /** @var mixed $payload */
        $payload = json_decode($result->stdout, true);

        if (! is_array($payload) || ! $this->hasOnlyStringKeys($payload)) {
            return [];
        }

        /** @var array<string, mixed> $payload */
        $success = $payload['success'] ?? null;

        if (! is_array($success) || ! $this->hasOnlyStringKeys($success)) {
            return [];
        }

        /** @var array<string, mixed> $success */
        $data = $success['data'] ?? null;

        if (! is_array($data) || ! $this->hasOnlyStringKeys($data)) {
            return [];
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function hasOnlyStringKeys(array $payload): bool
    {
        return array_all(array_keys($payload), fn ($key) => is_string($key));
    }
}

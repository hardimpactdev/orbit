<?php

declare(strict_types=1);

namespace App\Services\RemoteShell;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use RuntimeException;

final readonly class RemoteEnvFile
{
    public function __construct(
        private RunsInternalCommands $localExecutor,
    ) {}

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

    public function write(Node $node, string $path, string $contents): void
    {
        $result = $this->run($node, [
            'action' => 'write',
            'path' => $path,
            'contents' => $contents,
        ]);

        if (! $result->successful()) {
            throw new RuntimeException($result->output());
        }
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

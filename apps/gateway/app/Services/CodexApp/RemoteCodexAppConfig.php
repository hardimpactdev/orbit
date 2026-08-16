<?php

declare(strict_types=1);

namespace App\Services\CodexApp;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;

final readonly class RemoteCodexAppConfig
{
    public function __construct(
        private RemoteLocalExecutor $localExecutor,
    ) {}

    public function read(Node $node): RemoteShellResult
    {
        return $this->run($node, ['action' => 'read']);
    }

    /**
     * @param  array{label: string, ssh_alias: string, remote_path: string}  $project
     */
    public function mutate(Node $node, string $mutation, array $project): RemoteShellResult
    {
        return $this->run($node, [
            'action' => 'mutate',
            'mutation' => $mutation,
            'project' => $project,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function data(RemoteShellResult $result): array
    {
        $success = $this->envelope($result)['success'] ?? null;

        if (! is_array($success)) {
            return [];
        }

        return $this->normalizedArray($success['data'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(RemoteShellResult $result): array
    {
        $success = $this->envelope($result)['success'] ?? null;

        if (! is_array($success)) {
            return [];
        }

        return $this->normalizedArray($success['meta'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function error(RemoteShellResult $result): array
    {
        return $this->normalizedArray($this->envelope($result)['error'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function run(Node $node, array $payload): RemoteShellResult
    {
        $action = is_string($payload['action'] ?? null) ? $payload['action'] : 'unknown';
        $input = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:codex-app-config',
            transportOptions: [
                'input' => $input,
                'metadata' => [
                    'ORBIT_OPERATION_ID' => "codex-app-config.{$action}",
                ],
                'redact_stderr' => true,
                'redact_stdout' => $action !== 'read',
                'strict' => false,
                'timeout' => 30,
                'throw' => false,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @mago-expect analysis:mixed-assignment
     */
    private function envelope(RemoteShellResult $result): array
    {
        $payload = json_decode($result->stdout, associative: true);

        return $this->normalizedArray($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $key => $entry) {
            if (! is_string($key)) {
                continue;
            }

            $normalized[$key] = $entry;
        }

        return $normalized;
    }
}

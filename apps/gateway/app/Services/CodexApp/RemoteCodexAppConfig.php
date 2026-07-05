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

    public function write(Node $node, string $contents): RemoteShellResult
    {
        return $this->run($node, [
            'action' => 'write',
            'contents' => $contents,
        ]);
    }

    public function apply(Node $node): RemoteShellResult
    {
        return $this->run($node, ['action' => 'apply']);
    }

    /**
     * @return array<string, mixed>
     */
    public function data(RemoteShellResult $result): array
    {
        $payload = json_decode($result->stdout, associative: true);

        if (! is_array($payload)) {
            return [];
        }

        $success = $payload['success'] ?? null;

        if (! is_array($success)) {
            return [];
        }

        $data = $success['data'] ?? null;

        if (! is_array($data)) {
            return [];
        }

        $normalized = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
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
}

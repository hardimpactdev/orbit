<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;
use App\Services\RemoteShell\RemoteShellSuccessData;
use JsonException;

final readonly class RemoteCaddyConfig
{
    public function __construct(
        private RemoteLocalExecutor $localExecutor,
    ) {}

    public function readGlobal(Node $node): ?string
    {
        $result = $this->run($node, 'read-global', []);

        if (! $result->successful()) {
            return null;
        }

        try {
            $data = RemoteShellSuccessData::fromJsonEnvelope($result);
        } catch (JsonException) {
            return null;
        }

        return is_string($data['content'] ?? null) ? $data['content'] : null;
    }

    public function writeGlobal(Node $node, string $content): RemoteShellResult
    {
        return $this->run($node, 'write-global', ['content' => $content]);
    }

    public function writeSite(Node $node, string $domain, string $content, bool $backend = false): RemoteShellResult
    {
        return $this->run($node, 'write-site', [
            'domain' => $domain,
            'content' => $content,
            'backend' => $backend,
        ]);
    }

    public function reload(Node $node, string $container = 'orbit-caddy'): RemoteShellResult
    {
        return $this->run($node, 'reload', ['container' => $container]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function run(Node $node, string $action, array $payload): RemoteShellResult
    {
        return $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:caddy-config',
            arguments: [$action],
            transportOptions: [
                'input' => json_encode($payload, JSON_THROW_ON_ERROR),
                'metadata' => [
                    'ORBIT_OPERATION_ID' => "caddy-config.{$action}",
                ],
                'redact_stdout' => $action !== 'read-global',
                'redact_stderr' => false,
                'timeout' => 30,
                'throw' => false,
            ],
        );
    }
}

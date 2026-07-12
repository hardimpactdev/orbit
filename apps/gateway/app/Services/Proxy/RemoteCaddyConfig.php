<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\Gateway\CaddyGlobalConfig;
use App\Services\RemoteShell\RemoteShellSuccessData;
use App\Services\RemoteShell\RunsInternalCommands;
use App\Services\Runtime\OrbitCaddyContainer;
use JsonException;

final readonly class RemoteCaddyConfig
{
    public function __construct(
        private RunsInternalCommands $localExecutor,
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

    public function removeSite(Node $node, string $domain, string $container = 'orbit-caddy'): RemoteShellResult
    {
        return $this->run($node, 'remove-site', [
            'domain' => $domain,
            'container' => $container,
        ]);
    }

    public function reload(Node $node, string $container = 'orbit-caddy'): RemoteShellResult
    {
        return $this->run($node, 'reload', ['container' => $container]);
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    public function applyContainer(Node $node, array $spec): RemoteShellResult
    {
        $container = OrbitCaddyContainer::fromConfig($spec);

        return $this->run(
            $node,
            'apply-container',
            [
                'container' => [
                    ...$container->spec(),
                    'expected_hash' => $container->specHash(),
                ],
                'global_config' => new CaddyGlobalConfig()->fresh(),
            ],
            timeout: 120,
        );
    }

    public function startContainer(Node $node, string $container = 'orbit-caddy'): RemoteShellResult
    {
        return $this->run($node, 'start-container', ['container' => $container], timeout: 60);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function run(Node $node, string $action, array $payload, int $timeout = 30): RemoteShellResult
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
                'timeout' => $timeout,
                'throw' => false,
            ],
        );
    }
}

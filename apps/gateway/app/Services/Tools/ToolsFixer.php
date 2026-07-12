<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Convergence\ManagedFile;
use App\Services\Proxy\ProxyRouteRenderer;
use App\Services\Proxy\RemoteCaddyConfig;
use App\Services\RemoteShell\RemoteLocalExecutor;
use InvalidArgumentException;
use RuntimeException;

/**
 * @mago-expect lint:kan-defect
 */
final readonly class ToolsFixer
{
    // @orbit-ssh-lane transitional-ssh
    public function __construct(
        private RemoteShell $remoteShell,
        private ?ToolCatalog $catalog = null,
        private ?ProxyRouteRenderer $proxyRouteRenderer = null,
        private ?RemoteLocalExecutor $localExecutor = null,
        private ?RemoteCaddyConfig $caddyConfig = null,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function fix(NodeTool $tool, DriftEntry $entry): ?array
    {
        $tool->loadMissing('node');

        if ($tool->node === null) {
            return null;
        }

        $result = match ($entry->key) {
            'tool.config_missing', 'tool.config_mismatch' => $this->repairManagedConfig($tool, $entry),
            'tool.credentials_missing', 'tool.credentials_mismatch' => $this->repairManagedSecret($tool, $entry),
            'tool.container_missing',
            'tool.container_not_running',
            'tool.container_spec_mismatch',
                => $this->repairContainer($tool, $entry),
            'tool.agent_route_missing' => $this->fixAgentRoute($tool, $entry),
            'tool.agent_credentials_missing' => $this->fixAgentCredentials($tool, $entry),
            'tool.agent_user_missing' => $this->fixAgentUser($tool, $entry),
            default => $this->runRepairCommand($tool, $this->repairCommand($tool, $entry), $entry),
        };

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function runRepairCommand(NodeTool $tool, ?string $command, DriftEntry $entry): ?array
    {
        if ($command === null || $tool->node === null) {
            return null;
        }

        $action = match ($entry->key) {
            'tool.capability_missing' => 'install',
            'tool.version_mismatch' => 'update',
            default => null,
        };

        if ($action === null) {
            return null;
        }

        $result = $this->toolScriptDispatcher()->runForRegistry(
            node: $tool->node,
            tool: $tool->name,
            action: $action,
            script: $command,
            throw: true,
        );

        if ($result instanceof ToolRegistryFailure) {
            return $this->failedResult($tool, $entry, $result->message);
        }

        if (! $result->successful()) {
            return $this->failedResult($tool, $entry, $this->remoteFailureSummary($result));
        }

        return $this->fixResult($tool, $entry);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function repairContainer(NodeTool $tool, DriftEntry $entry): ?array
    {
        if ($tool->name !== 'caddy') {
            return $this->runRepairCommand($tool, $this->containerRepairCommand($tool), $entry);
        }

        $tool->loadMissing('node');

        if ($tool->node === null) {
            return null;
        }

        $config = is_array($tool->config) ? $tool->config : [];
        $container = $config['container'] ?? null;

        if (! is_array($container) || $container === []) {
            return null;
        }

        $containerConfig = [];

        foreach ($container as $key => $value) {
            $containerConfig[(string) $key] = $value;
        }

        $result = $this->caddyConfig()->applyContainer($tool->node, $containerConfig);

        if (! $result->successful()) {
            return $this->failedResult($tool, $entry, $this->remoteFailureSummary($result));
        }

        return $this->fixResult($tool, $entry);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixResult(NodeTool $tool, DriftEntry $entry): array
    {
        return [
            'family' => 'tool',
            'node' => $tool->node?->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => 'completed',
            'summary' => "Repaired tool {$tool->name} from gateway intent.",
            'details' => [
                'tool' => $tool->name,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failedResult(NodeTool $tool, DriftEntry $entry, string $error): array
    {
        return [
            'family' => 'tool',
            'node' => $tool->node?->name,
            'code' => $entry->key,
            'key' => $entry->key,
            'mode' => 'fix',
            'status' => 'failed',
            'summary' => $error,
            'details' => [
                'tool' => $tool->name,
                'error' => $error,
            ],
        ];
    }

    private function repairCommand(NodeTool $tool, DriftEntry $entry): ?string
    {
        $catalog = $this->catalog ?? app(ToolCatalog::class);
        $config = $this->configForToolScript($tool);

        if ($entry->key === 'tool.capability_missing') {
            return $catalog->installScript($tool->name, $config);
        }

        if ($entry->key !== 'tool.version_mismatch') {
            return null;
        }

        $command = $catalog->updateScript($tool->name, $config);

        return is_string($command) && $command !== '' ? $command : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function configForToolScript(NodeTool $tool): array
    {
        $config = is_array($tool->config) ? $tool->config : [];
        $tool->loadMissing('node');
        $managedUser = $tool->node?->user;

        return [
            ...$config,
            'managed_user' => is_string($managedUser) && trim($managedUser) !== '' ? trim($managedUser) : 'orbit',
        ];
    }

    private function containerRepairCommand(NodeTool $tool): ?string
    {
        $catalog = $this->catalog ?? app(ToolCatalog::class);

        return $catalog->updateScript($tool->name, is_array($tool->config) ? $tool->config : []);
    }

    private function caddyConfig(): RemoteCaddyConfig
    {
        return $this->caddyConfig ?? app(RemoteCaddyConfig::class);
    }

    private function remoteFailureSummary(RemoteShellResult $result): string
    {
        $output = trim($result->stderr.' '.$result->stdout);

        return $output !== '' ? $output : "Remote repair exited with code {$result->exitCode}.";
    }

    /**
     * @return array<string, mixed>|null
     */
    private function repairManagedConfig(NodeTool $tool, DriftEntry $entry): ?array
    {
        $config = is_array($tool->config) ? $tool->config : [];
        $managedConfig = is_array($config['managed_config'] ?? null) ? $config['managed_config'] : [];

        try {
            $file = ManagedFile::fromIntent($managedConfig);
        } catch (InvalidArgumentException) {
            return null;
        }

        $this->applyManagedFile($tool, $file);

        return $this->fixResult($tool, $entry);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function repairManagedSecret(NodeTool $tool, DriftEntry $entry): ?array
    {
        $credentials = is_array($tool->credentials) ? $tool->credentials : [];
        $managedSecret = is_array($credentials['managed_secret'] ?? null) ? $credentials['managed_secret'] : [];

        try {
            $file = ManagedFile::fromIntent(
                intent: $managedSecret,
                defaultMode: '0600',
                defaultDirectoryMode: '0700',
                sensitive: true,
            );
        } catch (InvalidArgumentException) {
            return null;
        }

        $this->applyManagedFile($tool, $file);

        return $this->fixResult($tool, $entry);
    }

    private function applyManagedFile(NodeTool $tool, ManagedFile $file): void
    {
        if ($tool->node === null) {
            throw new RuntimeException("Tool {$tool->name} has no target node.");
        }

        $plan = $file->plan($file->probe($tool->node, $this->remoteShell));
        $result = $file->apply($tool->node, $this->remoteShell, $plan);

        if (! $result->successful()) {
            throw new RuntimeException($result->summary);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fixAgentRoute(NodeTool $tool, DriftEntry $entry): ?array
    {
        $catalog = $this->catalog ?? app(ToolCatalog::class);

        if ($catalog->category($tool->name) !== 'agent') {
            return null;
        }

        $tld = $this->agentTldForNode($tool->node);

        if ($tld === null) {
            return null;
        }

        $domain = "{$tool->name}.{$tld}";

        $existing = ProxyRoute::query()
            ->where('domain', $domain)
            ->first();

        if ($existing instanceof ProxyRoute) {
            if ($existing->owner_type !== 'tool') {
                return null;
            }

            $existingOwner = is_array($existing->config) ? $existing->config['owner_name'] ?? null : null;

            if ($existingOwner !== $tool->name) {
                return null;
            }
        }

        $routeConfig = $this->agentProxyRouteConfig($tool->name);
        $sourceHash = $this->agentProxyRouteSourceHash($tool->node, $domain, $routeConfig);

        ProxyRoute::query()->updateOrCreate(
            ['domain' => $domain],
            [
                'node_id' => $tool->node?->id,
                'app_id' => null,
                'workspace_id' => null,
                'owner_type' => 'tool',
                'kind' => 'proxy',
                'config' => $routeConfig,
                'source_hash' => $sourceHash,
            ],
        );

        return $this->fixResult($tool, $entry);
    }

    /**
     * @return array{target: array{type: string, value: string}, upstream: string, owner_name: string}
     */
    private function agentProxyRouteConfig(string $tool): array
    {
        $upstream = 'http://'.ProxyRouteRenderer::HostLoopbackHostname.':8080';

        return [
            'target' => ['type' => 'upstream', 'value' => $upstream],
            'upstream' => $upstream,
            'owner_name' => $tool,
        ];
    }

    /**
     * @param  array{target: array{type: string, value: string}, upstream: string, owner_name: string}  $config
     */
    private function agentProxyRouteSourceHash(Node $node, string $domain, array $config): string
    {
        return ($this->proxyRouteRenderer ?? app(ProxyRouteRenderer::class))->sourceHash(new ProxyRoute([
            'node_id' => $node->id,
            'domain' => $domain,
            'kind' => 'proxy',
            'owner_type' => 'tool',
            'config' => $config,
        ]));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fixAgentCredentials(NodeTool $tool, DriftEntry $entry): ?array
    {
        $catalog = $this->catalog ?? app(ToolCatalog::class);

        if (! $catalog->hasCapability($tool->name, 'credentials')) {
            return null;
        }

        $node = $tool->node;

        if (! $node instanceof Node) {
            return null;
        }

        $tld = $this->agentTldForNode($node);
        $hostname = $tld !== null ? "{$tool->name}.{$tld}" : $tool->name;
        $credentialsScript = $catalog->credentialsScript($tool->name, ['hostname' => $hostname]);

        if ($credentialsScript === null) {
            return null;
        }

        $credResult = $this->toolScriptDispatcher()->runForRegistry(
            node: $node,
            tool: $tool->name,
            action: 'credentials',
            script: $credentialsScript,
        );

        if ($credResult instanceof ToolRegistryFailure) {
            return $this->failedResult($tool, $entry, $credResult->message);
        }

        if (! $credResult->successful()) {
            return null;
        }

        $parsed = json_decode(trim($credResult->stdout), true);

        if (! is_array($parsed) || $parsed === []) {
            return null;
        }

        $tool->credentials = ['fields' => $parsed];
        $tool->save();

        return $this->fixResult($tool, $entry);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixAgentUser(NodeTool $tool, DriftEntry $entry): array
    {
        if ($tool->node === null) {
            throw new RuntimeException("Tool {$tool->name} has no target node.");
        }

        $result = $this->localExecutor()->runInternal(
            node: $tool->node,
            commandName: 'internal:agent-user:ensure',
            transportOptions: [
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'agent-user.ensure',
                ],
                'timeout' => 60,
                'throw' => false,
            ],
        );

        if (! $result->successful()) {
            throw new RuntimeException('Could not ensure the Orbit agent runtime user.');
        }

        return $this->fixResult($tool, $entry);
    }

    private function localExecutor(): RemoteLocalExecutor
    {
        return $this->localExecutor ?? app(RemoteLocalExecutor::class);
    }

    private function toolScriptDispatcher(): ToolScriptDispatcher
    {
        return app(ToolScriptDispatcher::class);
    }

    private function agentTldForNode(Node $node): ?string
    {
        if (is_string($node->tld) && trim($node->tld) !== '') {
            return trim($node->tld);
        }

        return null;
    }
}

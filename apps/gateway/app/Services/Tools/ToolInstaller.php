<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Proxy\ProxyRouteRenderer;
use App\Services\RemoteShell\RemoteSecretFile;

final readonly class ToolInstaller
{
    public function __construct(
        private ToolCatalog $catalog,
        private ToolRegistry $registry,
        private RemoteShell $remoteShell,
        private NodeRoleAssignments $nodeRoleAssignments,
        private RemoteSecretFile $remoteSecretFile,
        private GitHubTokenResolver $githubTokenResolver,
        private ToolRuntimeIntentPlanner $runtimeIntents,
    ) {}

    /**
     * @return array<string, mixed>|ToolRegistryFailure
     */
    public function install(
        string $tool,
        ?string $node = null,
        ?string $app = null,
        string $expectedState = 'installed',
        array $config = [],
        ?string $version = null,
        ?string $runtime = null,
        ?string $instance = null,
    ): array|ToolRegistryFailure {
        if (! $this->catalog->supports($tool)) {
            return ToolRegistryFailure::unsupportedAction($tool, 'install');
        }

        if (! $this->catalog->hasCapability($tool, 'install')) {
            return ToolRegistryFailure::unsupportedAction($tool, 'install');
        }

        $requiredRole = $this->catalog->requiredNodeRole($tool);
        $targetNode = $this->resolveTargetNode($node, $app, $requiredRole);

        if ($targetNode instanceof ToolRegistryFailure) {
            return $targetNode;
        }

        if ($requiredRole !== null && ! $targetNode->hasActiveRole($requiredRole)) {
            return ToolRegistryFailure::nodeRoleRequired($tool, $targetNode->name, $requiredRole);
        }

        $instanceSelection = ToolInstanceSelector::forInstall(
            $this->catalog,
            $tool,
            ToolVersionRequest::fromInput($version),
            $instance,
        );

        if ($instanceSelection instanceof ToolRegistryFailure) {
            return $instanceSelection;
        }

        $runtimeIntent = $this->runtimeIntent(
            node: $targetNode,
            instance: $instanceSelection,
            runtime: $runtime,
            shouldPlan: $runtime !== null || $version !== null || $instance !== null,
        );

        if ($runtimeIntent instanceof ToolRegistryFailure) {
            return $runtimeIntent;
        }

        if ($runtimeIntent instanceof ToolRuntimeIntent) {
            $config = $this->withRuntimeConfig($config, $runtimeIntent);
        }

        $script = $this->catalog->installScript($tool, $config);

        if ($script === null) {
            return ToolRegistryFailure::unsupportedAction($tool, 'install');
        }

        if ($this->catalog->category($tool) === 'agent') {
            $agentConfig = $this->agentToolConfig($tool, $targetNode, $config);
            $routeConflict = $this->checkToolProxyRouteConflict($tool, $targetNode, $agentConfig);

            if ($routeConflict instanceof ToolRegistryFailure) {
                return $routeConflict;
            }
        }

        $row = NodeTool::query()->updateOrCreate(
            [
                'node_id' => $targetNode->id,
                'name' => $tool,
                'instance_key' => $instanceSelection->instanceKey,
            ],
            [
                'version_family' => $instanceSelection->versionFamily,
                'expected_version' => $instanceSelection->expectedVersion,
                'runtime' => $runtimeIntent->runtime ?? NodeTool::defaultRuntimeForTool($tool),
                'runtime_config' => $runtimeIntent?->spec(),
                'expected_state' => $expectedState,
                'config' => $config === [] ? null : $config,
            ],
        );

        $result = $this->runToolScriptWithGitHubAuth(
            node: $targetNode,
            tool: $tool,
            config: $config,
            scriptFactory: fn (array $config): string => (string) $this->catalog->installScript($tool, $config),
        );

        if (! $result->successful()) {
            return ToolRegistryFailure::remoteActionFailed(
                $tool,
                $targetNode->name,
                'install',
                $result->exitCode,
                trim($result->stderr),
            );
        }

        $agentConfig = $this->agentToolConfig($tool, $targetNode, $config);

        $credentialsScript = $this->catalog->credentialsScript($tool, $agentConfig);

        if ($credentialsScript !== null) {
            $credResult = $this->remoteShell->run($targetNode, $credentialsScript, ['throw' => false]);

            if ($credResult->successful()) {
                $parsed = json_decode(trim($credResult->stdout), true);

                if (is_array($parsed)) {
                    $row->credentials = ['fields' => $parsed];
                    $row->save();
                }
            }
        }

        $row->refresh();

        if ($this->catalog->category($tool) === 'agent') {
            $routeResult = $this->createToolProxyRoute($tool, $targetNode, $agentConfig);

            if ($routeResult instanceof ToolRegistryFailure) {
                return $routeResult;
            }
        }

        return [
            'name' => $tool,
            'node' => $targetNode->name,
            'state' => $row->expected_state,
            'instance' => $row->instance_key,
            'version_family' => $row->version_family,
            'version' => $row->expected_version,
            'runtime' => $row->runtime,
        ];
    }

    private function runtimeIntent(Node $node, ToolInstanceSelector $instance, ?string $runtime, bool $shouldPlan): ToolRuntimeIntent|ToolRegistryFailure|null
    {
        if (! $shouldPlan || ($runtime === null && $this->catalog->supportedRuntimes($instance->tool) === [])) {
            return null;
        }

        $selection = ToolRuntimeSelection::resolve(
            $this->catalog,
            $instance->tool,
            $runtime,
            (string) $node->platform,
        );

        return $this->runtimeIntents->plan($node, $instance, $selection);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function withRuntimeConfig(array $config, ToolRuntimeIntent $intent): array
    {
        return [
            ...$config,
            'endpoints' => $this->mergeEndpoints($config['endpoints'] ?? null, [$intent->endpoint]),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $newEndpoints
     * @return list<array<string, mixed>>
     */
    private function mergeEndpoints(mixed $existingEndpoints, array $newEndpoints): array
    {
        if (! is_array($existingEndpoints)) {
            return $newEndpoints;
        }

        return [
            ...array_values(array_filter($existingEndpoints, is_array(...))),
            ...$newEndpoints,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function agentToolConfig(string $tool, Node $node, array $config): array
    {
        if ($this->catalog->category($tool) !== 'agent') {
            return $config;
        }

        $tld = is_string($node->tld) ? trim($node->tld, '.') : '';
        $hostname = $tld !== '' ? "{$tool}.{$tld}" : $tool;

        return array_merge($config, ['hostname' => $hostname]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function checkToolProxyRouteConflict(string $tool, Node $node, array $config): ?ToolRegistryFailure
    {
        $tld = is_string($node->tld) ? trim($node->tld, '.') : '';
        $domain = $tld !== '' ? "{$tool}.{$tld}" : $tool;

        $existing = ProxyRoute::query()
            ->where('domain', $domain)
            ->first();

        if (! $existing instanceof ProxyRoute) {
            return null;
        }

        if ($existing->owner_type !== 'tool') {
            return ToolRegistryFailure::validation(
                'domain',
                $domain,
                "Proxy route '{$domain}' is already owned by {$existing->owner_type}.",
                ['domain' => $domain, 'owner_type' => $existing->owner_type],
            );
        }

        $existingOwner = is_array($existing->config) ? ($existing->config['owner_name'] ?? null) : null;

        if ($existingOwner !== $tool) {
            return ToolRegistryFailure::validation(
                'domain',
                $domain,
                "Proxy route '{$domain}' is already owned by tool '{$existingOwner}'.",
                ['domain' => $domain, 'existing_tool' => $existingOwner],
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function createToolProxyRoute(string $tool, Node $node, array $config): ?ToolRegistryFailure
    {
        $tld = is_string($node->tld) ? trim($node->tld, '.') : '';
        $domain = $tld !== '' ? "{$tool}.{$tld}" : $tool;
        $upstream = $config['upstream'] ?? 'http://'.ProxyRouteRenderer::HostLoopbackHostname.':8080';

        $existing = ProxyRoute::query()
            ->where('domain', $domain)
            ->first();

        if ($existing instanceof ProxyRoute) {
            if ($existing->owner_type !== 'tool') {
                return ToolRegistryFailure::validation(
                    'domain',
                    $domain,
                    "Proxy route '{$domain}' is already owned by {$existing->owner_type}.",
                    ['domain' => $domain, 'owner_type' => $existing->owner_type],
                );
            }

            $existingOwner = is_array($existing->config) ? ($existing->config['owner_name'] ?? null) : null;

            if ($existingOwner !== $tool) {
                return ToolRegistryFailure::validation(
                    'domain',
                    $domain,
                    "Proxy route '{$domain}' is already owned by tool '{$existingOwner}'.",
                    ['domain' => $domain, 'existing_tool' => $existingOwner],
                );
            }
        }

        $routeConfig = [
            'target' => ['type' => 'upstream', 'value' => $upstream],
            'upstream' => $upstream,
            'owner_name' => $tool,
        ];

        $sourceHash = app(ProxyRouteRenderer::class)->sourceHash(new ProxyRoute([
            'node_id' => $node->id,
            'domain' => $domain,
            'kind' => 'proxy',
            'owner_type' => 'tool',
            'config' => $routeConfig,
        ]));

        ProxyRoute::query()->updateOrCreate(
            ['domain' => $domain],
            [
                'node_id' => $node->id,
                'app_id' => null,
                'workspace_id' => null,
                'owner_type' => 'tool',
                'kind' => 'proxy',
                'config' => $routeConfig,
                'source_hash' => $sourceHash,
            ],
        );

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  callable(array<string, mixed>): string  $scriptFactory
     */
    private function runToolScriptWithGitHubAuth(Node $node, string $tool, array $config, callable $scriptFactory): RemoteShellResult
    {
        $token = $this->githubTokenForTool($tool);

        if ($token === null) {
            return $this->remoteShell->run($node, $scriptFactory($config), ['throw' => false]);
        }

        return $this->remoteSecretFile->stage(
            $node,
            $token,
            fn (string $path): RemoteShellResult => $this->remoteShell->run(
                $node,
                $scriptFactory([...$config, 'github_token_file' => $path]),
                ['throw' => false],
            ),
        );
    }

    private function githubTokenForTool(string $tool): ?string
    {
        if ($tool !== 'laravel-installer') {
            return null;
        }

        return $this->githubTokenResolver->token();
    }

    private function resolveTargetNode(?string $node, ?string $app, ?string $requiredRole): Node|ToolRegistryFailure
    {
        $validation = $this->registry->validateFilters($node, $app);
        $canResolveExplicitRequiredRoleNode = $this->canResolveExplicitRequiredRoleNode($validation, $node, $app, $requiredRole);

        if ($validation instanceof ToolRegistryFailure && ! $canResolveExplicitRequiredRoleNode) {
            return $validation;
        }

        if ($node !== null) {
            if ($requiredRole !== null) {
                $resolved = Node::query()
                    ->where('name', $node)
                    ->where('status', 'active')
                    ->first();

                if ($resolved instanceof Node) {
                    return $resolved;
                }
            }

            $resolved = Node::query()
                ->where('name', $node)
                ->whereIn('id', $this->nodeRoleAssignments->activeToolHostNodeIds())
                ->where('status', 'active')
                ->first();

            if ($resolved instanceof Node) {
                return $resolved;
            }

            if ($canResolveExplicitRequiredRoleNode && $validation instanceof ToolRegistryFailure) {
                return $validation;
            }
        }

        if ($app !== null) {
            $appModel = App::query()
                ->with('node')
                ->where(function ($query) use ($app): void {
                    $query->where('name', $app)
                        ->orWhere('domain', $app);
                })
                ->first();

            if ($appModel instanceof App && $appModel->node instanceof Node) {
                return $appModel->node;
            }
        }

        return ToolRegistryFailure::validation(
            'target',
            '',
            'A node or app target is required.',
        );
    }

    private function canResolveExplicitRequiredRoleNode(
        ?ToolRegistryFailure $validation,
        ?string $node,
        ?string $app,
        ?string $requiredRole,
    ): bool {
        if ($requiredRole === null || $node === null || $app !== null) {
            return false;
        }

        return $validation instanceof ToolRegistryFailure
            && $validation->code === 'validation_failed'
            && ($validation->meta['field'] ?? null) === 'node';
    }
}

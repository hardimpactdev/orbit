<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Actions\Processes\AddProcess;
use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeStatus;
use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Processes\ProcessOwnerContextResolver;
use App\Services\Proxy\ProxyRouteRenderer;
use App\Services\RemoteShell\ExplicitRemoteShellFallback;
use App\Services\RemoteShell\RemoteSecretFile;
use App\Tools\UserScopedCliTool;
use App\Tools\UserScopedCliUsers;

final readonly class ToolInstaller
{
    public function __construct(
        private ToolCatalog $catalog,
        private ToolRegistry $registry,
        private RemoteShell $remoteShell,
        private NodeRoleAssignments $nodeRoleAssignments,
        private RemoteSecretFile $remoteSecretFile,
        private GitHubTokenResolver $githubTokenResolver,
        private ProcessOwnerContextResolver $processContexts,
        private AddProcess $addProcess,
    ) {}

    /**
     * @param  array<array-key, mixed>  $config
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
        bool $withProcess = true,
    ): array|ToolRegistryFailure {
        $normalizedConfig = [];

        foreach ($config as $key => $value) {
            $normalizedConfig[(string) $key] = $value;
        }

        /** @var array<string, mixed> $config */
        $config = $normalizedConfig;

        if (! $this->catalog->supports($tool)) {
            return ToolRegistryFailure::unsupportedAction($tool, 'install');
        }

        if (! $this->catalog->hasCapability($tool, 'install')) {
            return ToolRegistryFailure::unsupportedAction($tool, 'install');
        }

        $targetNode = $this->resolveTargetNode($node, $app);

        if ($targetNode instanceof ToolRegistryFailure) {
            return $targetNode;
        }

        if (! $this->catalog->supportsNode($tool, $targetNode)) {
            return ToolRegistryFailure::unsupportedOnNode(
                tool: $tool,
                node: $targetNode->name,
                platform: $targetNode->platform,
                supportedOperatingSystems: $this->catalog->supportedOperatingSystems($tool),
            );
        }

        if ($runtime !== null) {
            return ToolRegistryFailure::validation(
                field: 'runtime',
                value: $runtime,
                message: 'Tools do not own runtime lifecycle. Use process:add --service for runnable services.',
                meta: ['reason' => 'unsupported_field'],
            );
        }

        if ($instance !== null) {
            return ToolRegistryFailure::validation(
                field: 'instance',
                value: $instance,
                message: 'Tools do not support runnable service instances. Use process:add --service for runnable services.',
                meta: ['reason' => 'unsupported_field'],
            );
        }

        $configFailure = app(ToolInstallConfigValidator::class)->validate($tool, $config);

        if ($configFailure instanceof ToolRegistryFailure) {
            return $configFailure;
        }

        $definition = $this->catalog->definition($tool);

        if ($definition instanceof UserScopedCliTool) {
            if ($version !== null && trim($version) !== '' && ! $definition->supportsInstallVersion()) {
                return ToolRegistryFailure::validation(
                    field: 'version',
                    value: $version,
                    message: "Tool '{$tool}' does not support managed install versions.",
                    meta: ['reason' => 'unsupported_field'],
                );
            }

            $nodeUser = is_string($targetNode->user) ? trim($targetNode->user) : '';
            $defaultUser = preg_match(UserScopedCliUsers::USERNAME_PATTERN, $nodeUser) === 1 ? $nodeUser : 'orbit';

            $installUsers = [];
            $requestedUsers = $config['install_users'] ?? [];

            if (is_array($requestedUsers)) {
                foreach ($requestedUsers as $username) {
                    if (! is_string($username) || preg_match(UserScopedCliUsers::USERNAME_PATTERN, $username) !== 1) {
                        continue;
                    }

                    $installUsers[] = $username;
                }
            }

            $config = [
                'default_user' => $defaultUser,
                'install_users' => array_values(array_unique($installUsers)),
                ...(
                    $definition->supportsInstallVersion() && $version !== null && trim($version) !== ''
                        ? ['version' => trim($version)]
                        : []
                ),
            ];
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

        $transport = app(ExplicitRemoteShellFallback::class);

        if (! $transport->allowed()) {
            return ToolRegistryFailure::nodeTransportRequired(
                $transport->message('tool:install'),
                $transport->meta(),
            );
        }

        $row = NodeTool::query()->updateOrCreate(
            [
                'node_id' => $targetNode->id,
                'name' => $tool,
            ],
            [
                'expected_version' => $version,
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

        $process = null;
        $relatedProcess = $this->catalog->relatedProcess($tool);

        if ($withProcess && $relatedProcess !== null) {
            $process = $this->configureRelatedProcess($targetNode, $relatedProcess);
        }

        return [
            'name' => $tool,
            'node' => $targetNode->name,
            'state' => $row->expected_state,
            'version' => $row->expected_version,
            'process' => $process,
        ];
    }

    /**
     * Converge the singleton service process a tool backs. Idempotent: an
     * existing process row is reported as converged, never duplicated.
     *
     * @param  array{name: string, command: string, runtime: string, tool: string}  $spec
     * @return array{name: string, runtime: string, tool: string, action: string}
     */
    private function configureRelatedProcess(Node $node, array $spec): array
    {
        $context = $this->processContexts->resolve(
            nodeName: $node->name,
            appName: null,
            workspaceName: null,
        );

        if ($context->ownerProcesses()->where('name', $spec['name'])->exists()) {
            return [
                'name' => $spec['name'],
                'runtime' => $spec['runtime'],
                'tool' => $spec['tool'],
                'action' => 'converged',
            ];
        }

        $this->addProcess->handle(
            context: $context,
            name: $spec['name'],
            command: $spec['command'],
            restartPolicy: ProcessRestartPolicy::Always,
            crashNotification: ProcessCrashNotification::None,
            start: true,
            runtime: ProcessRuntime::from($spec['runtime']),
            tool: $spec['tool'],
        );

        return [
            'name' => $spec['name'],
            'runtime' => $spec['runtime'],
            'tool' => $spec['tool'],
            'action' => 'configured',
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

        $existingOwner = is_array($existing->config) ? $existing->config['owner_name'] ?? null : null;

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

            $existingOwner = is_array($existing->config) ? $existing->config['owner_name'] ?? null : null;

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
    private function runToolScriptWithGitHubAuth(
        Node $node,
        string $tool,
        array $config,
        callable $scriptFactory,
    ): RemoteShellResult {
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

    private function resolveTargetNode(?string $node, ?string $app): Node|ToolRegistryFailure
    {
        $validation = $this->registry->validateFilters($node, $app);

        if ($validation instanceof ToolRegistryFailure) {
            return $validation;
        }

        if ($node !== null) {
            $resolved = Node::query()
                ->where('name', $node)
                ->whereNotIn('id', $this->gatewayNodeIds())
                ->where('status', NodeStatus::Active->value)
                ->first();

            if ($resolved instanceof Node) {
                return $resolved;
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

    /**
     * @return list<int>
     */
    private function gatewayNodeIds(): array
    {
        return $this->nodeRoleAssignments
            ->activeGatewayNodeQuery()
            ->pluck('id')
            ->map(fn (mixed $nodeId): int => (int) $nodeId)
            ->all();
    }
}

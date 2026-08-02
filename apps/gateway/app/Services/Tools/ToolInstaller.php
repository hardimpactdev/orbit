<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Actions\Processes\AddProcess;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Nodes\NodeStatus;
use App\Enums\ProcessCrashNotification;
use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Processes\ProcessOwnerContextResolver;
use App\Services\Proxy\AgentToolProxyRouteIntent;
use App\Services\RemoteShell\RemoteLocalExecutorTransportFailed;
use App\Services\RemoteShell\RemoteSecretFile;
use App\Tools\PhpCliTool;
use App\Tools\UserScopedCliTool;
use App\Tools\UserScopedCliUsers;
use Orbit\Core\Php\PhpCliVariant;
use RuntimeException;

final readonly class ToolInstaller
{
    public function __construct(
        private ToolCatalog $catalog,
        private ToolRegistry $registry,
        private ToolInstallPreflight $preflight,
        private ToolScriptDispatcher $toolScriptDispatcher,
        private ToolAppNodeResolver $instanceNodes,
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

        if ($runtime !== null) {
            return ToolRegistryFailure::validation(
                field: 'runtime',
                value: $runtime,
                message: 'Tools do not own runtime lifecycle. Use process:add --service for runnable services.',
                meta: ['reason' => 'unsupported_field'],
            );
        }

        $configFailure = app(ToolInstallConfigValidator::class)->validate($tool, $config);

        if ($configFailure instanceof ToolRegistryFailure) {
            return $configFailure;
        }

        $definition = $this->catalog->definition($tool);

        if ($tool === 'php-cli' && $definition instanceof PhpCliTool) {
            $resolved = $this->resolvePhpCliConfig($targetNode, $config);

            if ($resolved instanceof ToolRegistryFailure) {
                return $resolved;
            }

            $config = $resolved;
        }

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

        $scriptConfig = $this->catalog->scriptConfig($tool, $targetNode, $config);
        $script = $this->catalog->installScript($tool, $scriptConfig);

        if ($script === null) {
            return ToolRegistryFailure::unsupportedAction($tool, 'install');
        }

        $preflightFailure = $this->preflight->check($tool, $targetNode);

        if ($preflightFailure instanceof ToolRegistryFailure) {
            return $preflightFailure;
        }

        if ($this->catalog->category($tool) === 'agent') {
            $routeConflict = $this->checkToolProxyRouteConflict($tool, $targetNode, $scriptConfig);

            if ($routeConflict instanceof ToolRegistryFailure) {
                return $routeConflict;
            }
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
            config: $scriptConfig,
            scriptFactory: fn (array $config): string => (string) $this->catalog->installScript($tool, $config),
        );

        if ($result instanceof ToolRegistryFailure) {
            $row->delete();

            return $result;
        }

        if (! $result->successful()) {
            return ToolRegistryFailure::remoteActionFailed(
                $tool,
                $targetNode->name,
                'install',
                $result->exitCode,
                trim($result->stderr),
            );
        }

        $credentialsScript = $this->catalog->credentialsScript($tool, $scriptConfig);

        if ($credentialsScript !== null) {
            $credResult = $this->toolScriptDispatcher->runForRegistry(
                node: $targetNode,
                tool: $tool,
                action: 'credentials',
                script: $credentialsScript,
            );

            if ($credResult instanceof ToolRegistryFailure) {
                return $credResult;
            }

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
            $routeResult = $this->createToolProxyRoute($tool, $targetNode);

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
     * Manual `tool:install php-cli` with no config must resolve the role-derived
     * or already-stored variant instead of erasing NodeTool.config.
     *
     * Role ownership is authoritative: app-prod always persists standard and
     * app-dev always persists coverage, even when stored/explicit config conflicts.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|ToolRegistryFailure
     */
    private function resolvePhpCliConfig(Node $node, array $config): array|ToolRegistryFailure
    {
        $role = $this->phpCliRoleForNode($node);
        $definition = $this->catalog->definition('php-cli');

        if ($definition instanceof PhpCliTool && array_key_exists('variant', $config) && $role !== null) {
            try {
                $definition->assertVariantAllowedForRole($config, $role);
            } catch (RuntimeException $exception) {
                return ToolRegistryFailure::validation(
                    field: 'config.variant',
                    value: is_scalar($config['variant'] ?? null) ? (string) $config['variant'] : '',
                    message: $exception->getMessage(),
                    meta: ['reason' => 'role_variant_conflict', 'role' => $role],
                );
            }
        }

        if ($role !== null) {
            $variant = PhpCliVariant::forRole($role);

            return [
                ...$config,
                'variant' => $variant->value,
            ];
        }

        if (array_key_exists('variant', $config)) {
            $variant = PhpCliVariant::fromMixed($config['variant']);

            return [
                ...$config,
                'variant' => $variant->value,
            ];
        }

        $existing = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'php-cli')
            ->first();

        $rawConfig = $existing instanceof NodeTool ? $existing->config : null;
        /** @var array<string, mixed> $existingConfig */
        $existingConfig = is_array($rawConfig) ? $rawConfig : [];
        $storedVariant = PhpCliVariant::tryFromMixed($existingConfig['variant'] ?? null);

        if ($storedVariant instanceof PhpCliVariant) {
            /** @var array<string, mixed> $resolved */
            $resolved = [
                ...$existingConfig,
                ...$config,
                'variant' => $storedVariant->value,
            ];

            return $resolved;
        }

        /** @var array<string, mixed> $resolved */
        $resolved = [
            ...$config,
            'variant' => PhpCliVariant::Coverage->value,
        ];

        return $resolved;
    }

    private function phpCliRoleForNode(Node $node): ?string
    {
        return app(PhpCliVariantResolver::class)->appRoleForNode($node);
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

    private function createToolProxyRoute(string $tool, Node $node): ?ToolRegistryFailure
    {
        $nodeTool = NodeTool::query()
            ->with('node')
            ->where('node_id', $node->id)
            ->where('name', $tool)
            ->first();

        if (! $nodeTool instanceof NodeTool) {
            return ToolRegistryFailure::validation(
                'tool',
                $tool,
                "Tool '{$tool}' has no persisted install intent on node '{$node->name}'.",
                ['tool' => $tool, 'node' => $node->name],
            );
        }

        $expected = app(AgentToolProxyRouteIntent::class)->expectedRoute($nodeTool);

        if (! $expected instanceof ProxyRoute) {
            return ToolRegistryFailure::validation(
                'domain',
                '',
                "Tool '{$tool}' cannot derive its expected proxy route on node '{$node->name}'.",
                ['tool' => $tool, 'node' => $node->name],
            );
        }

        $domain = $expected->domain;

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

        app(AgentToolProxyRouteIntent::class)->persist($expected);

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
    ): RemoteShellResult|ToolRegistryFailure {
        $token = $this->githubTokenForTool($tool);

        if ($token === null) {
            return $this->toolScriptDispatcher->runForRegistry(
                node: $node,
                tool: $tool,
                action: 'install',
                script: $scriptFactory($config),
            );
        }

        try {
            return $this->remoteSecretFile->stage(
                $node,
                $token,
                fn (string $path): RemoteShellResult => $this->toolScriptDispatcher->run(
                    node: $node,
                    tool: $tool,
                    action: 'install',
                    script: $scriptFactory([...$config, 'github_token_file' => $path]),
                ),
            );
        } catch (RemoteLocalExecutorTransportFailed $exception) {
            return $this->toolScriptDispatcher->agentUnreachable($node, $tool, 'install', $exception);
        }
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
            $instanceNode = $this->instanceNodes->resolve($app);

            if ($instanceNode instanceof Node) {
                return $instanceNode;
            }
        }

        return ToolRegistryFailure::validation(
            'target',
            '',
            'A node or instance target is required.',
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

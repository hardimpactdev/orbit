<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Actions\Apps\CopyAppDevelopmentSetupSteps;
use App\Actions\Apps\EnactAppRuntime;
use App\Concerns\PromptsForRegistryEntities;
use App\Data\Apps\AppRuntimeConfig;
use App\Data\Apps\InstanceRuntimeRequirementsData;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\Apps\InstanceDriver;
use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeStatus;
use App\Exceptions\PromptAborted;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Php\PhpRuntimeCatalog;
use App\Services\Proxy\InstanceProxyRouteOwnershipResolver;
use App\Services\Support\GatewayActionResult;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Orbit\Sdk\Laravel\GatewayApiException;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

final class AppRegistrar
{
    use PromptsForRegistryEntities;

    private const int SUCCESS = 0;

    private const int FAILURE = 1;

    /** @var array<string, mixed> */
    private array $arguments = [];

    private ?string $output = null;

    public function __construct(
        private readonly RemoteAppSourcePathProbe $sourcePathProbe,
        private readonly AppRegistrationResultAction $resultAction,
        private readonly WorkspacePlacement $placement = new WorkspacePlacement,
        private readonly InstanceProxyRouteOwnershipResolver $routeOwnership = new InstanceProxyRouteOwnershipResolver,
        private readonly CopyAppDevelopmentSetupSteps $copyAppDevelopmentSetupSteps = new CopyAppDevelopmentSetupSteps,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function register(array $arguments): GatewayActionResult
    {
        $this->arguments = $arguments;
        $this->output = null;

        $exitCode = $this->handle(app(EnactAppRuntime::class));

        return GatewayActionResult::fromJsonOutput($exitCode, $this->output);
    }

    private function handle(EnactAppRuntime $enactAppRuntime): int
    {
        $input = $this->resolveInput();

        if (is_int($input)) {
            return $input;
        }

        $existingApp = App::query()
            ->with('instances')
            ->where('name', $input['app'])
            ->first();

        $selected = $this->resolveSelectedInstance($input, $existingApp);

        if (is_int($selected)) {
            return $selected;
        }

        $selectedConfig = $selected?->driver_config;
        $selectedConfig = $selectedConfig instanceof OrbitInstanceDriverConfigData ? $selectedConfig : null;
        $existingPlacement = $this->existingPlacement($existingApp);

        $node = $this->resolveTargetNode($input['node'], $existingApp, $selectedConfig);

        if (is_int($node)) {
            return $node;
        }

        $effectiveDomain = $input['domain'] ?? $selectedConfig?->domain ?? $existingPlacement?->domain;
        $environment = $this->registrationEnvironment($effectiveDomain, $node);
        $requiredRole = $environment === 'production' ? 'app-prod' : 'app-dev';
        $eligibleNode = $this->ensureEligibleNode($node, $requiredRole);

        if (is_int($eligibleNode)) {
            return $eligibleNode;
        }

        if ($input['instance'] !== null && ! $selected instanceof Instance && $input['instance'] !== $environment) {
            return $this->failValidation(
                'name',
                "Instance '{$input['app']}.{$input['instance']}' was not found.",
            );
        }

        $selectedName = $selected?->name ?? $input['instance'] ?? $environment;
        $currentNodeId = $selectedConfig?->node_id ?? $existingPlacement?->node_id;
        $currentPath = $selectedConfig?->path ?? $existingPlacement?->path;
        $path = $input['path'] ?? $currentPath;

        if ((! is_string($path) || $path === '') && $this->isInteractiveInput()) {
            $path = trim(text(label: 'Instance path on node', required: true));
        }

        if (! is_string($path) || $path === '') {
            return $this->failValidation(
                'path',
                'The --path option is required when registering an unmanaged instance.',
            );
        }

        if (! str_starts_with($path, '/')) {
            return $this->failValidation('path', 'Path must be absolute.');
        }

        if (
            ! $existingApp instanceof App
            && $this->isInteractiveInput()
            && ! confirm('Adopt existing project path?', default: true)
        ) {
            return $this->failValidation('path', 'App path adoption was cancelled.');
        }

        if (! $this->sourcePathProbe->exists($node, $path)) {
            return $this->failValidation('path', "Path '{$path}' does not exist on node '{$node->name}'.");
        }

        $pathCollision = $this->pathCollision($input['app'], $selectedName, $node, $path);

        if (is_int($pathCollision)) {
            return $pathCollision;
        }

        $explicitMove = $this->isExplicitMove($input, $path, $node, $existingApp, $currentNodeId, $currentPath);

        if ($existingApp instanceof App && $currentNodeId !== null && $currentNodeId !== $node->id && ! $explicitMove) {
            $currentNodeName = Node::query()->find($currentNodeId)?->name;

            return $this->failCommand(
                code: 'app.path_collision',
                message: "Instance '{$input['app']}.{$selectedName}' is already registered on node '{$currentNodeName}'. "
                ."Moving it requires the dotted selector '{$input['app']}.{$selectedName}' plus explicit --node and --path.",
                meta: [
                    'path' => $currentPath,
                    'existing_instance' => "{$input['app']}.{$selectedName}",
                    'serving_node' => $currentNodeName,
                ],
            );
        }

        if ($existingApp instanceof App && $currentPath !== null && $currentPath !== $path && ! $explicitMove) {
            return $this->failCommand(
                code: 'app.path_collision',
                message: "Instance '{$input['app']}.{$selectedName}' is already registered at '{$currentPath}'. "
                ."Moving it requires the dotted selector '{$input['app']}.{$selectedName}' plus explicit --node and --path.",
                meta: [
                    'path' => $currentPath,
                    'existing_instance' => "{$input['app']}.{$selectedName}",
                    'serving_node' => $node->name,
                ],
            );
        }

        $routeConflict = $this->routeConflict($effectiveDomain, $input['app'], $node, $existingApp, $selected);

        if ($routeConflict instanceof ProxyRoute) {
            return $this->failCommand(
                code: 'proxy.domain_conflict',
                message: "Proxy route domain '{$routeConflict->domain}' is already registered.",
                meta: [
                    'domain' => $routeConflict->domain,
                    'owner_type' => $routeConflict->owner_type,
                    'kind' => $routeConflict->kind,
                ],
            );
        }

        $action = $this->registrationAction($existingApp, $explicitMove);
        $app = $this->registerAppRecord(
            $input,
            $node,
            $path,
            $existingApp,
            $environment,
            $selected,
            $selectedName,
            $effectiveDomain,
        );
        $warnings = [
            ...$this->sharedPolicyFanoutWarnings($existingApp, $app, $selectedName),
            ...$enactAppRuntime->handle($app),
        ];
        $action = $this->resultAction->afterEnactment($action, $warnings);

        return $this->successCommand(
            [
                'result' => ['action' => $action],
                'app' => $this->appPayload($app),
                'instance' => $this->instancePayload($app, $selectedName),
            ],
            $warnings,
            $node->name,
        );
    }

    /**
     * Runtime proxy transport is app-owned shared policy: a registration that
     * changes it fans out to every sibling instance, so each sibling is named
     * instead of changing silently.
     *
     * @return list<array<string, string>>
     */
    private function sharedPolicyFanoutWarnings(?App $before, App $after, string $selectedName): array
    {
        if (! $before instanceof App) {
            return [];
        }

        // PHP version is no longer app-owned: each instance holds its own, so a
        // register run cannot move a sibling's PHP. Runtime proxy transport is
        // still app-owned and still fans out, so it keeps its warning.
        $changes = [];

        $transportBefore = $this->proxyTransportLabel($before->runtime_config);
        $transportAfter = $this->proxyTransportLabel($after->runtime_config);

        if ($transportBefore !== $transportAfter) {
            $changes[] = "proxy transport {$transportAfter}";
        }

        if ($changes === []) {
            return [];
        }

        $changedFacts = implode(', ', $changes);
        $warnings = [];
        $siblings = Instance::query()
            ->where('app_id', $after->id)
            ->where('name', '!=', $selectedName)
            ->where('driver', InstanceDriver::Orbit)
            ->get();

        foreach ($siblings as $sibling) {
            $config = $sibling->driver_config;
            $nodeName = $config instanceof OrbitInstanceDriverConfigData ? $config->node : null;
            $placement = $nodeName === null ? '' : " on node '{$nodeName}'";

            $warnings[] = [
                'code' => 'instance.shared_runtime_policy_applied',
                'family' => 'instance',
                'message' =>
                    "Sibling instance '{$after->name}.{$sibling->name}'{$placement} now follows the "
                        ."shared app policy ({$changedFacts}) changed by this run.",
                'next_command' => 'doctor --family=instance',
            ];
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>|null  $runtimeConfig
     */
    private function proxyTransportLabel(?array $runtimeConfig): string
    {
        $transport = $runtimeConfig['proxy_transport'] ?? null;

        return is_string($transport) && $transport !== '' ? $transport : 'http';
    }

    /**
     * @param  array{app: string, instance: ?string, node: ?string, path: ?string, root: ?string, php_version: ?string, domain: ?string, runtime_proxy_transport: ?string}  $input
     */
    private function resolveSelectedInstance(array $input, ?App $existingApp): Instance|int|null
    {
        if ($input['instance'] !== null) {
            if (! $existingApp instanceof App) {
                return $this->failValidation(
                    'name',
                    "App '{$input['app']}' is not registered. Register the first instance with the bare app name.",
                );
            }

            $selected = $existingApp->instances->firstWhere('name', $input['instance']);

            if ($selected instanceof Instance) {
                return $this->requireOrbitInstance($input['app'], $selected);
            }

            return null;
        }

        if (! $existingApp instanceof App) {
            return null;
        }

        $instances = $existingApp->instances;

        if ($instances->count() > 1) {
            return $this->failCommand(
                code: 'validation_failed',
                message: "App '{$input['app']}' requires a concrete instance selector.",
                meta: [
                    'field' => 'name',
                    'reason' => 'instance_required',
                    'instances' => $instances->pluck('name')->sort()->values()->all(),
                ],
            );
        }

        $single = $instances->first();

        if (! $single instanceof Instance) {
            return null;
        }

        return $this->requireOrbitInstance($input['app'], $single);
    }

    private function requireOrbitInstance(string $appName, Instance $instance): Instance|int
    {
        if ($instance->driver !== InstanceDriver::Orbit) {
            return $this->failValidation(
                'name',
                "Instance '{$appName}.{$instance->name}' is not an Orbit-managed instance.",
            );
        }

        return $instance;
    }

    /**
     * The existing app's placement, sourced from its primary concrete Orbit
     * instance. App owns no placement columns to fall back to.
     */
    private function existingPlacement(?App $app): ?OrbitInstanceDriverConfigData
    {
        if (! $app instanceof App) {
            return null;
        }

        $config = $this->placement->appPrimaryInstance($app)?->driver_config;

        return $config instanceof OrbitInstanceDriverConfigData ? $config : null;
    }

    private function pathCollision(string $appName, string $selectedName, Node $node, string $path): ?int
    {
        // Path ownership is instance-authoritative: a path on a node is owned by
        // the concrete Orbit instance placed there, not an App shadow column.
        $owningInstance = Instance::query()
            ->with('app')
            ->where('driver', InstanceDriver::Orbit->value)
            ->where('driver_config->data->node_id', $node->id)
            ->where('driver_config->data->path', $path)
            ->get()
            ->first(
                fn (Instance $candidate): bool => (
                    $candidate->app?->name !== $appName
                    || $candidate->name !== $selectedName
                ),
            );

        if (! $owningInstance instanceof Instance) {
            return null;
        }

        $owningSelector = $owningInstance->app?->name.'.'.$owningInstance->name;

        return $this->failCommand(
            code: 'app.path_collision',
            message: "Path '{$path}' on node '{$node->name}' is already owned by instance '{$owningSelector}'.",
            meta: [
                'path' => $path,
                'existing_instance' => $owningSelector,
                'serving_node' => $node->name,
            ],
        );
    }

    /**
     * @param  array{app: string, instance: ?string, node: ?string, path: ?string, root: ?string, php_version: ?string, domain: ?string, runtime_proxy_transport: ?string}  $input
     *
     * @mago-expect lint:excessive-parameter-list
     */
    private function isExplicitMove(
        array $input,
        string $path,
        Node $node,
        ?App $existingApp,
        ?int $currentNodeId,
        ?string $currentPath,
    ): bool {
        if (! $existingApp instanceof App) {
            return false;
        }

        if ($input['instance'] === null || $input['node'] === null || $input['path'] === null) {
            return false;
        }

        return $currentNodeId !== $node->id || $currentPath !== $path;
    }

    private function registrationAction(?App $existingApp, bool $explicitMove): string
    {
        if (! $existingApp instanceof App) {
            return 'adopted';
        }

        return $explicitMove ? 'moved' : 'converged';
    }

    /**
     * @param  array{app: string, instance: ?string, node: ?string, path: ?string, root: ?string, php_version: ?string, domain: ?string, runtime_proxy_transport: ?string}  $input
     *
     * @mago-expect lint:excessive-parameter-list
     */
    private function registerAppRecord(
        array $input,
        Node $node,
        string $path,
        ?App $existingApp,
        string $environment,
        ?Instance $selected,
        string $selectedName,
        ?string $effectiveDomain,
    ): App {
        $selectedConfig = $selected?->driver_config;
        $selectedConfig = $selectedConfig instanceof OrbitInstanceDriverConfigData ? $selectedConfig : null;
        $existingPlacement = $this->existingPlacement($existingApp);
        $documentRoot =
            $input['root'] ?? $selectedConfig?->document_root ?? $existingPlacement?->document_root ?? 'public';
        $phpVersion = $input['php_version'] ?? $existingApp?->php_version ?? PhpRuntimeCatalog::DEFAULT;
        // The default instance is the app's primary concrete instance (a new app
        // makes its first instance the default). App owns no environment column.
        $isDefaultInstance =
            ! $existingApp instanceof App
            || $selectedName === $this->placement->appPrimaryInstance($existingApp)?->name;

        // The App is logical-only: registration writes placement/adoption to the
        // concrete instance below, never to the app record.
        $attributes = [
            'repository' => $existingApp?->repository,
        ];

        if (! $existingApp instanceof App) {
            $attributes['php_version'] = $phpVersion;
        }

        if ($input['runtime_proxy_transport'] !== null) {
            $attributes['runtime_config'] = $this->runtimeConfigForStorage($input['runtime_proxy_transport']);
        }

        return DB::transaction(function () use (
            $input,
            $node,
            $path,
            $existingApp,
            $environment,
            $selected,
            $selectedName,
            $effectiveDomain,
            $selectedConfig,
            $phpVersion,
            $isDefaultInstance,
            $attributes,
            $documentRoot,
        ): App {
            $app = App::query()->updateOrCreate(
                ['name' => $input['app']],
                $attributes,
            );

            $instance = $app->instances()->updateOrCreate(
                ['name' => $selectedName],
                [
                    'driver' => InstanceDriver::Orbit,
                    // Instance-scoped: an explicit --php-version writes this
                    // instance only; otherwise keep its stored value, falling back
                    // to the app default when creating the first instance.
                    'php_version' => $input['php_version'] ?? $selected?->php_version ?? $phpVersion,
                    'adopted' => $selected instanceof Instance
                        ? $selected->adopted
                        : ! $existingApp instanceof App,
                    'driver_config' => new OrbitInstanceDriverConfigData(
                        node_id: $node->id,
                        node: $node->name,
                        path: $path,
                        document_root: $documentRoot,
                        domain: $input['domain'] ?? $selectedConfig?->domain
                            ?? ($isDefaultInstance ? $effectiveDomain : null),
                    ),
                    'runtime_requirements' => $selected?->runtime_requirements ?? new InstanceRuntimeRequirementsData,
                ],
            );

            if (
                $instance->wasRecentlyCreated
                && $environment !== 'production'
                && $node->hasActiveRole(NodeRoleName::AppDevelopment->value)
            ) {
                $this->copyAppDevelopmentSetupSteps->handle($app, $instance);
            }

            $app->unsetRelation('instances');

            return $app;
        });
    }

    /**
     * @return array{app: string, instance: ?string, node: ?string, path: ?string, root: ?string, php_version: ?string, domain: ?string, runtime_proxy_transport: ?string}|int
     */
    private function resolveInput(): array|int
    {
        $name = $this->stringArgument('name');
        if ($name === null && $this->isInteractiveInput()) {
            $name = trim(text(label: 'App name', required: true));
        }

        if ($name === null) {
            return $this->failValidation('name', 'App name is required.');
        }

        $appName = $name;
        $instanceName = null;

        if (str_contains($name, '.')) {
            [$appName, $instanceName] = explode('.', $name, 2);
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $appName) || mb_strlen($appName) > 40) {
            return $this->failValidation('name', 'App name must be a slug of 40 characters or fewer.');
        }

        if (
            $instanceName !== null
            && (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $instanceName)
            || mb_strlen($instanceName) > 40)
        ) {
            return $this->failValidation('name', 'Instance name must be a slug of 40 characters or fewer.');
        }

        $path = $this->stringOption('path');

        if ($path !== null && ! str_starts_with($path, '/')) {
            return $this->failValidation('path', 'Path must be absolute.');
        }

        $root = $this->stringOption('root');
        $phpVersion = $this->stringOption('php-version');
        $domain = $this->stringOption('domain');

        if ($root !== null && preg_match('/[\x00-\x1F;`$|&<>"\'\\\\]/', $root)) {
            return $this->failValidation('root', 'Document root is invalid.');
        }

        if ($phpVersion !== null && ! in_array($phpVersion, PhpRuntimeCatalog::SUPPORTED, true)) {
            return $this->failValidation('php_version', 'Unsupported PHP version.');
        }

        if ($domain !== null && filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return $this->failValidation('domain', 'Production domain is invalid.');
        }

        $runtimeProxyTransport = $this->stringOption('runtime-proxy-transport');

        if ($runtimeProxyTransport !== null) {
            try {
                AppRuntimeConfig::fromProxyTransportOption($runtimeProxyTransport);
            } catch (InvalidArgumentException) {
                return $this->failValidation(
                    'runtime_proxy_transport',
                    "Runtime proxy transport must be 'http' or 'https'.",
                );
            }
        }

        return [
            'app' => $appName,
            'instance' => $instanceName,
            'node' => $this->stringOption('node'),
            'path' => $path,
            'root' => $root,
            'php_version' => $phpVersion,
            'domain' => $domain,
            'runtime_proxy_transport' => $runtimeProxyTransport,
        ];
    }

    /**
     * @return array{proxy_transport: string}|null
     */
    private function runtimeConfigForStorage(?string $runtimeProxyTransport): ?array
    {
        if ($runtimeProxyTransport === null) {
            return null;
        }

        $config = AppRuntimeConfig::fromProxyTransportOption($runtimeProxyTransport);

        if (! $config->usesInnerHttpsProxyTransport()) {
            return null;
        }

        return $config->toArray();
    }

    private function resolveTargetNode(
        ?string $nodeName,
        ?App $existingApp,
        ?OrbitInstanceDriverConfigData $selectedConfig,
    ): Node|int {
        $selectedNodeId = $selectedConfig?->node_id;

        if ($nodeName === null && $selectedNodeId !== null) {
            $node = Node::query()->find($selectedNodeId);

            if ($node instanceof Node) {
                return $node;
            }
        }

        if ($nodeName === null && $existingApp instanceof App) {
            $node = app(WorkspacePlacement::class)->runtimeNode($existingApp, null);

            if ($node instanceof Node) {
                return $node;
            }
        }

        if ($nodeName === null) {
            if ($this->isInteractiveInput()) {
                try {
                    $selectedNode = $this->promptForVisibleNode(
                        label: 'Select target app node',
                        role: 'app-host',
                    );
                } catch (PromptAborted) {
                    return $this->failValidation('node', 'Operation cancelled.');
                }

                if ($selectedNode instanceof GatewayApiException) {
                    return $this->failCommand(
                        code: $selectedNode->errorCode() ?? 'gateway_unavailable',
                        message: $selectedNode->getMessage(),
                        meta: $selectedNode->errorMeta(),
                    );
                }

                $nodeName = $selectedNode;
            }

            if ($nodeName === null) {
                $nodeNames = Node::query()
                    ->whereIn('id', app(NodeRoleAssignments::class)->activeAppHostNodeIds())
                    ->where('status', NodeStatus::Active->value)
                    ->orderBy('name')
                    ->pluck('name')
                    ->all();

                if (count($nodeNames) !== 1) {
                    return $this->failValidation(
                        'node',
                        'The --node option is required when the target app node cannot be inferred.',
                    );
                }

                $nodeName = (string) $nodeNames[0];
            }
        }

        $node = Node::query()->where('name', $nodeName)->first();

        if (! $node instanceof Node) {
            return $this->failValidation('node', "Node '{$nodeName}' was not found.");
        }

        return $node;
    }

    private function ensureEligibleNode(Node $node, string $requiredRole): Node|int
    {
        if ($node->isActive() && app(NodeRoleAssignments::class)->nodeHasActiveRole($node, $requiredRole)) {
            return $node;
        }

        return $this->failCommand(
            code: 'app.ineligible_node',
            message: "Node '{$node->name}' is not an active app node.",
            meta: [
                'node' => $node->name,
                'required_role' => $requiredRole,
                'status' => $node->status->value,
            ],
        );
    }

    private function registrationEnvironment(?string $domain, Node $node): string
    {
        return $this->placement->environmentFor($domain, $node);
    }

    private function routeConflict(
        ?string $effectiveDomain,
        string $appName,
        Node $node,
        ?App $existingApp,
        ?Instance $selectedInstance,
    ): ?ProxyRoute {
        $domain = $effectiveDomain ?? $this->developmentDomain($appName, $node);

        $route = ProxyRoute::query()->where('domain', $domain)->first();

        if (! $route instanceof ProxyRoute) {
            return null;
        }

        if (
            $existingApp instanceof App
            && $selectedInstance instanceof Instance
            && $selectedInstance->app_id === $existingApp->id
            && $route->owner_type === 'app'
            && $this->routeOwnership->resolve($route)?->is($selectedInstance)
        ) {
            return null;
        }

        return $route;
    }

    private function developmentDomain(string $name, Node $node): string
    {
        $tld = is_string($node->tld) ? trim($node->tld, '.') : '';

        if ($tld === '') {
            return $name;
        }

        return "{$name}.{$tld}";
    }

    private function stringArgument(string $key): ?string
    {
        $value = $this->argument($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function wantsJson(): bool
    {
        return $this->option('json') === true;
    }

    private function isInteractiveInput(): bool
    {
        return false;
    }

    private function argument(string $key): mixed
    {
        return $this->arguments[$key] ?? null;
    }

    private function option(string $key): mixed
    {
        return $this->arguments["--{$key}"] ?? null;
    }

    private function line(string $message): void
    {
        $this->output = $message;
    }

    private function error(string $message): void
    {
        $this->output = $message;
    }

    /**
     * @return array<string, mixed>
     */
    private function appPayload(App $app): array
    {
        return app(AppResponsePayload::class)->forApp($app);
    }

    /**
     * @return array<string, mixed>
     */
    private function instancePayload(App $app, string $instanceName): array
    {
        $instance = Instance::query()
            ->where('app_id', $app->id)
            ->where('name', $instanceName)
            ->firstOrFail();

        return app(InstancePayloads::class)->placement($instance);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $warnings
     */
    private function successCommand(array $data, array $warnings, string $nodeName): int
    {
        if (! $this->wantsJson()) {
            /** @var array{name?: string} $app */
            $app = is_array($data['app'] ?? null) ? $data['app'] : [];
            /** @var array{url?: string} $instance */
            $instance = is_array($data['instance'] ?? null) ? $data['instance'] : [];
            $action = (string) ($data['result']['action'] ?? '');

            $this->line($this->successLine($action, $app));
            $this->line('URL: '.($instance['url'] ?? ''));

            if ($warnings !== []) {
                $this->line('Warnings:');

                foreach ($warnings as $warning) {
                    $this->line('- '.(string) ($warning['message'] ?? $warning['code'] ?? 'Warning'));

                    if (isset($warning['next_command']) && is_string($warning['next_command'])) {
                        $this->line('  Retry with: orbit '.$warning['next_command']);
                    }
                }
            }

            return self::SUCCESS;
        }

        $this->line(json_encode([
            'success' => [
                'data' => $data,
                'meta' => [
                    'node' => $nodeName,
                    'warnings' => $warnings,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $app
     */
    private function successLine(string $action, array $app): string
    {
        $name = (string) ($app['name'] ?? '');
        $node = (string) ($app['node'] ?? '');
        $path = (string) ($app['path'] ?? '');

        return match ($action) {
            'adopted' => "Instance for app '{$name}' successfully adopted from path '{$path}' on node '{$node}'.",
            'moved' => "Instance for app '{$name}' successfully moved to path '{$path}' on node '{$node}'.",
            'converged' => "Instance for app '{$name}' is already converged on node '{$node}'. No changes were needed.",
            default => "Instance for app '{$name}' successfully registered on node '{$node}'.",
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function failCommand(string $code, string $message, array $meta): int
    {
        if (! $this->wantsJson()) {
            $this->error($message);

            return self::FAILURE;
        }

        $this->line(json_encode([
            'error' => [
                'code' => $code,
                'message' => $message,
                'meta' => $meta,
            ],
        ], JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }

    private function failValidation(string $field, string $message): int
    {
        return $this->failCommand(
            code: 'validation_failed',
            message: $message,
            meta: ['field' => $field],
        );
    }
}

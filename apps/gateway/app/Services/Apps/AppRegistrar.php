<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Actions\Apps\EnactAppRuntime;
use App\Concerns\PromptsForRegistryEntities;
use App\Data\Apps\AppInstanceRuntimeRequirementsData;
use App\Data\Apps\AppRuntimeConfig;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\Apps\AppInstanceDriver;
use App\Enums\Nodes\NodeStatus;
use App\Exceptions\PromptAborted;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProxyRoute;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Php\PhpRuntimeCatalog;
use App\Services\Support\GatewayActionResult;
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

        $existingApp = Project::query()
            ->with('node')
            ->where('name', $input['name'])
            ->first();

        $node = $this->resolveTargetNode($input['node'], $existingApp);

        if (is_int($node)) {
            return $node;
        }

        $environment = $this->registrationEnvironment($input['domain'], $node);
        $requiredRole = $environment === 'production' ? 'app-prod' : 'app-dev';
        $eligibleNode = $this->ensureEligibleNode($node, $requiredRole);

        if (is_int($eligibleNode)) {
            return $eligibleNode;
        }

        $path = $input['path'] ?? $existingApp?->path;

        if ((! is_string($path) || $path === '') && $this->isInteractiveInput()) {
            $path = trim(text(label: 'App path on node', required: true));
        }

        if (! is_string($path) || $path === '') {
            return $this->failValidation('path', 'The --path option is required when registering an unmanaged app.');
        }

        if (! str_starts_with($path, '/')) {
            return $this->failValidation('path', 'Path must be absolute.');
        }

        if (
            ! $existingApp instanceof Project
            && $this->isInteractiveInput()
            && ! confirm('Adopt existing project path?', default: true)
        ) {
            return $this->failValidation('path', 'Project path adoption was cancelled.');
        }

        if (! $this->sourcePathProbe->exists($node, $path)) {
            return $this->failValidation('path', "Path '{$path}' does not exist on node '{$node->name}'.");
        }

        $pathOwner = Project::query()
            ->where('node_id', $node->id)
            ->where('path', $path)
            ->where('name', '!=', $input['name'])
            ->first();

        if ($pathOwner instanceof Project) {
            return $this->failCommand(
                code: 'project.path_collision',
                message: "Path '{$path}' on node '{$node->name}' is already owned by project '{$pathOwner->name}'.",
                meta: [
                    'path' => $path,
                    'existing_project' => $pathOwner->name,
                    'node' => $node->name,
                ],
            );
        }

        $explicitMove = $this->isExplicitMove($input, $path, $node, $existingApp);

        if ($existingApp instanceof Project && $existingApp->node_id !== $node->id && ! $explicitMove) {
            return $this->failCommand(
                code: 'project.path_collision',
                message: "Project '{$input['name']}' is already registered on node '{$existingApp->node?->name}'.",
                meta: [
                    'path' => $existingApp->path,
                    'existing_project' => $existingApp->name,
                    'node' => $existingApp->node?->name,
                ],
            );
        }

        if ($existingApp instanceof Project && $existingApp->path !== $path && ! $explicitMove) {
            return $this->failCommand(
                code: 'project.path_collision',
                message: "Project '{$input['name']}' is already registered at '{$existingApp->path}'.",
                meta: [
                    'path' => $path,
                    'existing_project' => $existingApp->name,
                    'node' => $node->name,
                ],
            );
        }

        $routeConflict = $this->routeConflict($input, $node, $existingApp);

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

        if (! $this->wantsJson()) {
            return $this->registerForHuman($input, $node, $path, $existingApp, $enactAppRuntime);
        }

        $action = $this->registrationAction($existingApp, $explicitMove);
        $app = $this->registerAppRecord($input, $node, $path, $existingApp, $environment);
        $warnings = $enactAppRuntime->handle($app);
        $action = $this->resultAction->afterEnactment($action, $warnings);

        return $this->successCommand(
            [
                'result' => ['action' => $action],
                'project' => $this->appPayload($app),
                'instance' => $this->instancePayload($app),
            ],
            $warnings,
            $node->name,
        );
    }

    /**
     * @param  array{name: string, node: ?string, path: ?string, root: string, php_version: string, domain: ?string, runtime_proxy_transport: ?string}  $input
     */
    private function registerForHuman(
        array $input,
        Node $node,
        string $path,
        ?Project $existingApp,
        EnactAppRuntime $enactAppRuntime,
    ): int {
        $action = $this->registrationAction(
            $existingApp,
            $this->isExplicitMove($input, $path, $node, $existingApp),
        );
        $environment = $this->registrationEnvironment($input['domain'], $node);
        $app = $this->registerAppRecord($input, $node, $path, $existingApp, $environment);
        $warnings = $enactAppRuntime->handle($app);
        $action = $this->resultAction->afterEnactment($action, $warnings);

        return $this->successCommand(
            [
                'result' => ['action' => $action],
                'project' => $this->appPayload($app),
                'instance' => $this->instancePayload($app),
            ],
            $warnings,
            $node->name,
        );
    }

    /**
     * @param  array{name: string, node: ?string, path: ?string, root: string, php_version: string, domain: ?string, runtime_proxy_transport: ?string}  $input
     */
    private function isExplicitMove(array $input, string $path, Node $node, ?Project $existingApp): bool
    {
        if (! $existingApp instanceof Project) {
            return false;
        }

        if ($input['node'] === null || $input['path'] === null) {
            return false;
        }

        return $existingApp->node_id !== $node->id || $existingApp->path !== $path;
    }

    private function registrationAction(?Project $existingApp, bool $explicitMove): string
    {
        if (! $existingApp instanceof Project) {
            return 'adopted';
        }

        return $explicitMove ? 'moved' : 'converged';
    }

    /**
     * @param  array{name: string, node: ?string, path: ?string, root: string, php_version: string, domain: ?string, runtime_proxy_transport: ?string}  $input
     */
    private function registerAppRecord(
        array $input,
        Node $node,
        string $path,
        ?Project $existingApp,
        string $environment,
    ): Project {
        $attributes = [
            'node_id' => $node->id,
            'environment' => $environment,
            'domain' => $input['domain'] ?? $existingApp?->domain,
            'path' => $path,
            'document_root' => $input['root'],
            'repository' => $existingApp?->repository,
            'php_version' => $input['php_version'],
            'adopted' => $existingApp instanceof Project ? $existingApp->adopted : true,
        ];

        if ($input['runtime_proxy_transport'] !== null) {
            $attributes['runtime_config'] = $this->runtimeConfigForStorage($input['runtime_proxy_transport']);
        }

        $app = Project::query()->updateOrCreate(
            ['name' => $input['name']],
            $attributes,
        );

        $app->setRelation('node', $node);
        $this->ensureDefaultInstance($app, $node);

        return $app;
    }

    private function ensureDefaultInstance(Project $app, Node $node): void
    {
        $app->instances()->updateOrCreate(
            ['name' => $app->environment],
            [
                'driver' => AppInstanceDriver::Orbit,
                'driver_config' => new OrbitAppInstanceDriverConfigData(
                    node_id: $node->id,
                    node: $node->name,
                    path: $app->path,
                    document_root: $app->document_root,
                    domain: $app->domain,
                ),
                'runtime_requirements' => new AppInstanceRuntimeRequirementsData,
            ],
        );
    }

    /**
     * @return array{name: string, node: ?string, path: ?string, root: string, php_version: string, domain: ?string, runtime_proxy_transport: ?string}|int
     */
    private function resolveInput(): array|int
    {
        $name = $this->stringArgument('name');
        if ($name === null && $this->isInteractiveInput()) {
            $name = trim(text(label: 'Project name', required: true));
        }

        if ($name === null) {
            return $this->failValidation('name', 'Project name is required.');
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name) || mb_strlen($name) > 40) {
            return $this->failValidation('name', 'Project name must be a slug of 40 characters or fewer.');
        }

        $path = $this->stringOption('path');

        if ($path !== null && ! str_starts_with($path, '/')) {
            return $this->failValidation('path', 'Path must be absolute.');
        }

        $root = $this->stringOption('root') ?? 'public';
        $phpVersion = $this->stringOption('php-version') ?? PhpRuntimeCatalog::DEFAULT;
        $domain = $this->stringOption('domain');

        if ($root === '' || preg_match('/[\x00-\x1F;`$|&<>"\'\\\\]/', $root)) {
            return $this->failValidation('root', 'Document root is invalid.');
        }

        if (! in_array($phpVersion, PhpRuntimeCatalog::SUPPORTED, true)) {
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
            'name' => $name,
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

    private function resolveTargetNode(?string $nodeName, ?Project $existingApp): Node|int
    {
        if ($nodeName === null && $existingApp instanceof Project) {
            $existingApp->loadMissing('node');
            $node = $existingApp->node;

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
            code: 'project.ineligible_node',
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
        if ($domain === null) {
            return 'development';
        }

        if ($this->isDevelopmentDomainForNode($domain, $node)) {
            return 'development';
        }

        return 'production';
    }

    private function isDevelopmentDomainForNode(string $domain, Node $node): bool
    {
        if (! app(NodeRoleAssignments::class)->nodeHasActiveRole($node, 'app-dev')) {
            return false;
        }

        $tld = is_string($node->tld) ? trim($node->tld, '.') : '';

        return $tld !== '' && str_ends_with($domain, ".{$tld}");
    }

    /**
     * @param  array{name: string, node: ?string, path: ?string, root: string, php_version: string, domain: ?string, runtime_proxy_transport: ?string}  $input
     */
    private function routeConflict(array $input, Node $node, ?Project $existingApp): ?ProxyRoute
    {
        $domain =
            $input['domain'] ?? (
                $existingApp instanceof Project ? $existingApp->domain : null
            ) ?? $this->developmentDomain(
                $input['name'],
                $node,
            );

        $route = ProxyRoute::query()->where('domain', $domain)->first();

        if (! $route instanceof ProxyRoute) {
            return null;
        }

        if ($existingApp instanceof Project && $route->app_id === $existingApp->id) {
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
    private function appPayload(Project $app): array
    {
        return app(AppResponsePayload::class)->forApp($app);
    }

    /**
     * @return array<string, mixed>
     */
    private function instancePayload(Project $app): array
    {
        $instance = AppInstance::query()
            ->where('app_id', $app->id)
            ->where('name', $app->environment)
            ->firstOrFail();

        return app(AppInstancePayloads::class)->placement($instance);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $warnings
     */
    private function successCommand(array $data, array $warnings, string $nodeName): int
    {
        if (! $this->wantsJson()) {
            /** @var array{name?: string} $project */
            $project = is_array($data['project'] ?? null) ? $data['project'] : [];
            /** @var array{url?: string} $instance */
            $instance = is_array($data['instance'] ?? null) ? $data['instance'] : [];
            $action = (string) ($data['result']['action'] ?? '');

            $this->line($this->successLine($action, $project));
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
            'adopted' => "Instance for project '{$name}' successfully adopted from path '{$path}' on node '{$node}'.",
            'converged'
                => "Instance for project '{$name}' is already converged on node '{$node}'. No changes were needed.",
            default => "Instance for project '{$name}' successfully registered on node '{$node}'.",
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

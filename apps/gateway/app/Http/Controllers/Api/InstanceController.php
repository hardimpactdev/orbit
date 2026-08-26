<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Actions\Apps\CopyAppDevelopmentSetupSteps;
use App\Data\Apps\InstanceRuntimeRequirementsData;
use App\Data\Apps\LaravelCloudInstanceDriverConfigData;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Enums\ActivityLogType;
use App\Enums\Apps\InstanceDriver;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Apps\InstancePayloads;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ValueError;

final class InstanceController implements Loggable
{
    private ?App $activitySubject = null;

    private string $currentAction = 'list';

    public function __construct(
        private readonly InstancePayloads $payloads,
        private readonly NodeAccessAuthorizer $authorizer,
        private readonly NodeRoleAssignments $nodeRoleAssignments,
        private readonly WorkspacePlacement $workspacePlacement,
        private readonly CopyAppDevelopmentSetupSteps $copyAppDevelopmentSetupSteps,
    ) {}

    public function all(Request $request): JsonResponse
    {
        $this->currentAction = 'list';
        $caller = $this->caller($request);

        if ($caller instanceof JsonResponse) {
            return $caller;
        }

        $callerIsGateway = $this->nodeRoleAssignments->nodeIsGateway($caller);

        if (! $callerIsGateway && ! $this->callerMayReadAnyInstance($caller)) {
            return $this->authorizationFailed('instance:read');
        }

        $app = $this->stringInput($request, 'app');
        $query = Instance::query()->with(['app', 'runtimeMounts', 'latestDeploymentRun']);

        if ($app !== null) {
            $targetApp = App::query()->where('name', $app)->first();

            if (! $targetApp instanceof App) {
                return $this->projectNotFound($app);
            }

            $this->activitySubject = $targetApp;
            $query->where('app_id', $targetApp->id);
        }

        $instances = [];

        foreach ($query
            ->orderBy('app_id')
            ->orderBy('name')
            ->get() as $instance) {
            if (! $instance instanceof Instance) {
                continue;
            }

            $instances[] = $instance;
        }

        $instances = array_values(array_filter(
            $instances,
            function (Instance $instance) use ($caller, $callerIsGateway): bool {
                if ($instance->driver !== InstanceDriver::Orbit) {
                    return $callerIsGateway;
                }

                $servingNode = $this->workspacePlacement->nodeForInstance($instance);

                return $servingNode instanceof Node
                && $this->authorizer->allows($caller, $servingNode, 'instance:read');
            },
        ));

        return $this->success([
            'instances' => array_map($this->payloads->instance(...), $instances),
        ], ['count' => count($instances)]);
    }

    public function index(string $app, Request $request): JsonResponse
    {
        $this->currentAction = 'list';
        $targetApp = $this->resolveApp($app);

        if ($targetApp instanceof JsonResponse) {
            return $targetApp;
        }

        $this->activitySubject = $targetApp;

        if (! $targetApp instanceof App) {
            return $this->appNotFound($app);
        }

        $caller = $this->caller($request);

        if ($caller instanceof JsonResponse) {
            return $caller;
        }

        $callerIsGateway = $this->nodeRoleAssignments->nodeIsGateway($caller);

        if (! $callerIsGateway && ! $this->callerMayReadAnyInstance($caller)) {
            return $this->authorizationFailed('instance:read');
        }

        /** @var list<Instance> $instances */
        $instances = $targetApp->instances()->with(['runtimeMounts', 'latestDeploymentRun'])->get()->all();
        $instances = array_values(array_filter(
            $instances,
            function (Instance $instance) use ($caller, $callerIsGateway): bool {
                if ($instance->driver !== InstanceDriver::Orbit) {
                    return $callerIsGateway;
                }

                $servingNode = $this->workspacePlacement->nodeForInstance($instance);

                return $servingNode instanceof Node
                && $this->authorizer->allows($caller, $servingNode, 'instance:read');
            },
        ));

        return $this->success([
            'app' => $targetApp->name,
            'instances' => array_map(
                $this->payloads->instance(...),
                $instances,
            ),
        ], ['count' => count($instances)]);
    }

    private function callerMayReadAnyInstance(Node $caller): bool
    {
        $appNodeIds = array_values(array_unique([
            ...$this->nodeRoleAssignments->activeNodeIdsForRole('app-dev'),
            ...$this->nodeRoleAssignments->activeNodeIdsForRole('app-prod'),
        ]));

        foreach ($appNodeIds as $appNodeId) {
            $node = Node::query()->find($appNodeId);

            if (! $node instanceof Node) {
                continue;
            }

            if ($this->authorizer->allows($caller, $node, 'instance:read')) {
                return true;
            }
        }

        return false;
    }

    public function show(string $app, string $instance, Request $request): JsonResponse
    {
        $this->currentAction = 'show';
        $resolved = $this->resolveInstance($app, $instance);

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        [$targetApp, $targetInstance] = $resolved;
        $this->activitySubject = $targetApp;

        $authorization = $this->authorizeInstance($request, $targetInstance, 'instance:read');

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        return $this->success($this->payloads->withCompatibility($targetInstance));
    }

    public function store(string $app, Request $request): JsonResponse
    {
        $this->currentAction = 'add';
        $targetApp = $this->resolveApp($app);

        if ($targetApp instanceof JsonResponse) {
            return $targetApp;
        }

        $this->activitySubject = $targetApp;

        if (! $targetApp instanceof App) {
            return $this->appNotFound($app);
        }

        $name = $this->stringInput($request, 'name') ?? $this->stringInput($request, 'instance');

        if ($name === null) {
            return $this->validationFailed('name', 'Instance name is required.');
        }

        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $name) || mb_strlen($name) > 40) {
            return $this->validationFailed('name', 'Instance name must be a slug of 40 characters or fewer.');
        }

        if ($targetApp->instances()->where('name', $name)->exists()) {
            return $this->validationFailed(
                'name',
                "Instance '{$name}' already exists for app '{$targetApp->name}'.",
                [
                    'app' => $targetApp->name,
                    'instance' => $name,
                ],
                422,
            );
        }

        $driver = $this->driver($request);

        if ($driver instanceof JsonResponse) {
            return $driver;
        }

        $caller = $this->caller($request);

        if ($caller instanceof JsonResponse) {
            return $caller;
        }

        if ($driver === InstanceDriver::LaravelCloud && ! $this->nodeRoleAssignments->nodeIsGateway($caller)) {
            return $this->externalInstanceAuthorizationFailed();
        }

        $driverConfig = match ($driver) {
            InstanceDriver::Orbit => $this->orbitDriverConfig($request),
            InstanceDriver::LaravelCloud => $this->laravelCloudDriverConfig($request),
        };

        if ($driverConfig instanceof JsonResponse) {
            return $driverConfig;
        }

        $authorization = $this->authorizeOrbitTarget($caller, $driverConfig, $name);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        $instance = DB::transaction(function () use ($targetApp, $name, $driver, $driverConfig, $request): Instance {
            $instance = $targetApp->instances()->create([
                'name' => $name,
                'driver' => $driver,
                'adopted' => false,
                'driver_config' => $driverConfig,
                // Copy the app creation template onto the new instance. A null
                // row would resolve through the app at runtime, so a later
                // change to the app default would move this instance.
                'php_version' => $targetApp->php_version,
                'runtime_requirements' => new InstanceRuntimeRequirementsData(php_extensions: $this->phpExtensions(
                    $request,
                )),
            ]);

            $config = $instance->driver_config;
            if ($driver === InstanceDriver::Orbit && $config instanceof OrbitInstanceDriverConfigData) {
                $node = $config->node_id === null ? null : Node::query()->find($config->node_id);
                if ($node instanceof Node && $this->nodeRoleAssignments->nodeHasActiveRole($node, 'app-dev')) {
                    $this->copyAppDevelopmentSetupSteps->handle($targetApp, $instance);
                }
            }

            return $instance;
        });

        return $this->success($this->payloads->withCompatibility($instance));
    }

    public function destroy(string $app, string $instance, Request $request): JsonResponse
    {
        $this->currentAction = 'remove';
        $resolved = $this->resolveInstance($app, $instance);

        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        [$targetApp, $targetInstance] = $resolved;
        $this->activitySubject = $targetApp;

        $authorization = $this->authorizeInstance($request, $targetInstance, 'instance:write');

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        if (! $request->boolean('destructive_consent') && ! $request->boolean('force')) {
            return $this->validationFailed(
                'force',
                'Removing an instance requires destructive consent.',
                [
                    'field' => 'force',
                    'reason' => 'destructive_consent_required',
                ],
                422,
            );
        }

        $targetInstance->delete();

        return $this->success([
            'result' => [
                'action' => 'removed',
                'app' => $targetApp->name,
                'instance' => $instance,
            ],
        ]);
    }

    private function driver(Request $request): InstanceDriver|JsonResponse
    {
        $value = $this->stringInput($request, 'driver') ?? InstanceDriver::Orbit->value;

        try {
            return InstanceDriver::from($value);
        } catch (ValueError) {
            return $this->validationFailed(
                'driver',
                'Driver must be one of: orbit, laravel-cloud.',
                [
                    'field' => 'driver',
                    'value' => $value,
                    'allowed' => array_map(
                        static fn (InstanceDriver $driver): string => $driver->value,
                        InstanceDriver::cases(),
                    ),
                ],
                422,
            );
        }
    }

    private function orbitDriverConfig(Request $request): OrbitInstanceDriverConfigData|JsonResponse
    {
        $nodeSelector = $this->stringInput($request, 'node');
        $node = $nodeSelector === null ? null : Node::query()->where('name', $nodeSelector)->first();

        if (! $node instanceof Node) {
            return $this->validationFailed(
                'node',
                'Orbit instances require a valid --node value.',
                [
                    'field' => 'node',
                    'value' => $nodeSelector,
                ],
                422,
            );
        }

        $path = $this->stringInput($request, 'path');

        if ($path === null) {
            return $this->validationFailed('path', 'Orbit instances require a valid --path value.');
        }

        $documentRoot = $this->stringInput($request, 'root') ?? $this->stringInput($request, 'document_root');

        if ($documentRoot === null) {
            return $this->validationFailed('root', 'Orbit instances require a valid --root value.');
        }

        return new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: $path,
            document_root: $documentRoot,
            domain: $this->stringInput($request, 'domain'),
        );
    }

    private function laravelCloudDriverConfig(Request $request): LaravelCloudInstanceDriverConfigData|JsonResponse
    {
        $application =
            $this->stringInput($request, 'cloud_application') ?? $this->stringInput(
                $request,
                'cloud_app',
            ) ?? $this->stringInput($request, 'cloud_application_id') ?? $this->stringInput(
                $request,
                'cloud_application_name',
            );
        $environment =
            $this->stringInput($request, 'cloud_environment') ?? $this->stringInput(
                $request,
                'cloud_environment_id',
            ) ?? $this->stringInput($request, 'cloud_environment_name');

        if ($application === null) {
            return $this->validationFailed(
                'cloud_application',
                'Laravel Cloud app selector is required for laravel-cloud instances.',
            );
        }

        $environmentSelection = $this->laravelCloudEnvironmentSelection($request, $environment);

        if ($environmentSelection instanceof JsonResponse) {
            return $environmentSelection;
        }

        return new LaravelCloudInstanceDriverConfigData(
            organization_id: $this->stringInput($request, 'cloud_organization_id'),
            organization_name: $this->stringInput($request, 'cloud_organization_name'),
            application_id: $this->stringInput($request, 'cloud_application_id'),
            application_name: $this->stringInput($request, 'cloud_application_name'),
            environment_id: $environmentSelection['id'],
            environment_name: $environmentSelection['name'],
            environment_reused: $environmentSelection['reused'],
            environment_created: $environmentSelection['created'],
            application: $application,
            environment: $environmentSelection['selector'],
            domain: $this->stringInput($request, 'domain'),
        );
    }

    /**
     * @return array{id: ?string, name: ?string, selector: string, reused: bool, created: bool}|JsonResponse
     */
    private function laravelCloudEnvironmentSelection(Request $request, ?string $environment): array|JsonResponse
    {
        $explicitId = $this->stringInput($request, 'cloud_environment_id');
        $explicitName = $this->stringInput($request, 'cloud_environment_name');
        $environmentCreated = $this->booleanInput($request, 'cloud_environment_created') ?? false;
        $environmentReused = $this->booleanInput($request, 'cloud_environment_reused');
        $environments = $this->laravelCloudEnvironmentCandidates($request);

        if ($environment !== null) {
            $candidate = $this->matchingLaravelCloudEnvironment(
                $environments,
                $explicitId,
                $explicitName,
                $environment,
            );

            return [
                'id' => $explicitId ?? $candidate['id'] ?? null,
                'name' => $explicitName ?? $candidate['name'] ?? null,
                'selector' => $environment,
                'reused' => $environmentReused ?? $candidate !== null && ! $environmentCreated,
                'created' => $environmentCreated,
            ];
        }

        if (
            ($this->booleanInput($request, 'cloud_environment_create') ?? false)
            || ($this->booleanInput($request, 'cloud_create_environment') ?? false)
        ) {
            return $this->validationFailed(
                'cloud_environment',
                'Laravel Cloud environment selector is required when creating a new environment.',
                [
                    'reason' => 'cloud_environment_creation_requires_selector',
                ],
                422,
            );
        }

        $defaultEnvironmentId = $this->stringInput($request, 'cloud_default_environment_id');

        if ($defaultEnvironmentId !== null) {
            $candidate = $this->matchingLaravelCloudEnvironment(
                $environments,
                $defaultEnvironmentId,
                null,
                $defaultEnvironmentId,
            );

            return $this->selectedLaravelCloudEnvironment(
                $candidate['id'] ?? $defaultEnvironmentId,
                $candidate['name'] ?? null,
            );
        }

        $mainEnvironment = $this->namedLaravelCloudEnvironment($environments, 'main');

        if ($mainEnvironment !== null) {
            return $this->selectedLaravelCloudEnvironment($mainEnvironment['id'], $mainEnvironment['name']);
        }

        if (count($environments) === 1) {
            $environment = $environments[0];

            return $this->selectedLaravelCloudEnvironment($environment['id'], $environment['name']);
        }

        if (count($environments) > 1) {
            return $this->validationFailed(
                'cloud_environment',
                'Laravel Cloud environment is ambiguous; choose an existing environment explicitly.',
                [
                    'reason' => 'ambiguous_cloud_environment',
                    'environments' => $environments,
                ],
                422,
            );
        }

        return $this->validationFailed(
            'cloud_environment',
            'Laravel Cloud environment selector is required for laravel-cloud instances.',
        );
    }

    /**
     * @return list<array{id: ?string, name: ?string}>
     */
    private function laravelCloudEnvironmentCandidates(Request $request): array
    {
        $environments = $request->input('cloud_environments', []);

        if (! is_array($environments)) {
            return [];
        }

        $normalized = [];

        foreach ($environments as $environment) {
            $candidate = $this->laravelCloudEnvironmentCandidate($environment);

            if ($candidate === null || in_array($candidate, $normalized, true)) {
                continue;
            }

            $normalized[] = $candidate;
        }

        return $normalized;
    }

    /**
     * @return array{id: ?string, name: ?string}|null
     */
    private function laravelCloudEnvironmentCandidate(mixed $environment): ?array
    {
        if (is_string($environment)) {
            $environment = trim($environment);

            return $environment === '' ? null : ['id' => null, 'name' => $environment];
        }

        if (! is_array($environment)) {
            return null;
        }

        $id =
            $this->arrayStringInput($environment, 'id') ?? $this->arrayStringInput(
                $environment,
                'environment_id',
            ) ?? $this->arrayStringInput($environment, 'uuid');
        $name =
            $this->arrayStringInput($environment, 'name') ?? $this->arrayStringInput(
                $environment,
                'environment_name',
            ) ?? $this->arrayStringInput($environment, 'slug');

        return $id === null && $name === null ? null : ['id' => $id, 'name' => $name];
    }

    /**
     * @param  list<array{id: ?string, name: ?string}>  $environments
     * @return array{id: ?string, name: ?string}|null
     */
    private function matchingLaravelCloudEnvironment(
        array $environments,
        ?string $id,
        ?string $name,
        string $selector,
    ): ?array {
        foreach ($environments as $environment) {
            if ($id !== null && $environment['id'] === $id) {
                return $environment;
            }

            if ($name !== null && $environment['name'] === $name) {
                return $environment;
            }

            if ($environment['id'] === $selector || $environment['name'] === $selector) {
                return $environment;
            }
        }

        return null;
    }

    /**
     * @param  list<array{id: ?string, name: ?string}>  $environments
     * @return array{id: ?string, name: ?string}|null
     */
    private function namedLaravelCloudEnvironment(array $environments, string $name): ?array
    {
        foreach ($environments as $environment) {
            if ($environment['name'] !== null && strtolower($environment['name']) === $name) {
                return $environment;
            }
        }

        return null;
    }

    /**
     * @return array{id: ?string, name: ?string, selector: string, reused: bool, created: bool}
     */
    private function selectedLaravelCloudEnvironment(?string $id, ?string $name): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'selector' => $id ?? $name ?? '',
            'reused' => true,
            'created' => false,
        ];
    }

    /**
     * @return list<string>
     */
    private function phpExtensions(Request $request): array
    {
        $extensions = $request->input('php_extensions', []);

        if (is_string($extensions)) {
            $extensions = [$extensions];
        }

        if (! is_array($extensions)) {
            return [];
        }

        /** @var list<string> $normalized */
        $normalized = [];

        foreach ($extensions as $extension) {
            if (! is_string($extension)) {
                continue;
            }

            $value = strtolower(trim($extension));

            if ($value === '') {
                continue;
            }

            $normalized[] = $value;
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    /**
     * @return array{0: App, 1: Instance}|JsonResponse
     */
    private function resolveInstance(string $app, string $instance): array|JsonResponse
    {
        $targetApp = $this->resolveApp($app);

        if ($targetApp instanceof JsonResponse) {
            return $targetApp;
        }

        if (! $targetApp instanceof App) {
            return $this->appNotFound($app);
        }

        $targetInstance = $targetApp
            ->instances()
            ->with(['app', 'runtimeMounts'])
            ->where('name', $instance)
            ->first();

        if (! $targetInstance instanceof Instance) {
            return response()->json([
                'error' => [
                    'code' => 'instance.not_found',
                    'message' => "Instance '{$instance}' was not found for app '{$targetApp->name}'.",
                    'meta' => [
                        'app' => $targetApp->name,
                        'instance' => $instance,
                    ],
                ],
            ], 404);
        }

        return [$targetApp, $targetInstance];
    }

    private function resolveApp(string $selector): App|JsonResponse|null
    {
        $baseQuery = App::query()->with(['instances']);
        $nameMatch = (clone $baseQuery)->where('name', $selector)->first();

        if ($nameMatch instanceof App) {
            return $nameMatch;
        }

        $matches = $baseQuery
            ->get()
            ->filter(function (App $app) use ($selector): bool {
                foreach ($app->instances as $instance) {
                    $placement = $this->payloads->placement($instance);
                    $host = parse_url((string) ($placement['url'] ?? ''), PHP_URL_HOST);

                    if (($placement['domain'] ?? null) === $selector || $host === $selector) {
                        return true;
                    }
                }

                return false;
            })
            ->values();

        if ($matches->count() > 1) {
            return $this->validationFailed(
                'app',
                "App selector '{$selector}' is ambiguous; use the app name.",
                [
                    'reason' => 'ambiguous_app_selector',
                    'selector' => $selector,
                ],
                422,
            );
        }

        $match = $matches->first();

        return $match instanceof App ? $match : null;
    }

    private function stringInput(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function booleanInput(Request $request, string $key): ?bool
    {
        if (! $request->has($key)) {
            return null;
        }

        $value = $request->input($key);

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (! is_string($value)) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }

    /**
     * @param  array<mixed>  $values
     */
    private function arrayStringInput(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $meta
     */
    private function success(array $data, array $meta = []): JsonResponse
    {
        return response()->json([
            'success' => [
                'data' => $data,
                'meta' => $meta === [] ? (object) [] : $meta,
            ],
        ]);
    }

    private function appNotFound(string $app): JsonResponse
    {
        return $this->projectNotFound($app);
    }

    private function projectNotFound(string $app): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'app.not_found',
                'message' => "App '{$app}' not found.",
                'meta' => ['app' => $app],
            ],
        ], 404);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function validationFailed(string $field, string $message, array $meta = [], int $status = 422): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'validation_failed',
                'message' => $message,
                'meta' => $meta === [] ? ['field' => $field] : ['field' => $field, ...$meta],
            ],
        ], $status);
    }

    private function caller(Request $request): Node|JsonResponse
    {
        /** @var mixed $caller */
        $caller = $request->user();

        return $caller instanceof Node
            ? $caller
            : $this->authorizationFailed('instance:read', message: 'Peer identity unknown.');
    }

    private function authorizeInstance(Request $request, Instance $instance, string $permission): ?JsonResponse
    {
        $caller = $this->caller($request);

        if ($caller instanceof JsonResponse) {
            return $caller;
        }

        if ($instance->driver !== InstanceDriver::Orbit) {
            return $this->nodeRoleAssignments->nodeIsGateway($caller)
                ? null
                : $this->externalInstanceAuthorizationFailed($instance->name);
        }

        $servingNode = $this->workspacePlacement->nodeForInstance($instance);

        if (! $servingNode instanceof Node || ! $this->authorizer->allows($caller, $servingNode, $permission)) {
            return $this->authorizationFailed($permission, $servingNode, $instance->name);
        }

        return null;
    }

    private function authorizeOrbitTarget(
        Node $caller,
        OrbitInstanceDriverConfigData|LaravelCloudInstanceDriverConfigData $driverConfig,
        string $instance,
    ): ?JsonResponse {
        if (! $driverConfig instanceof OrbitInstanceDriverConfigData) {
            return null;
        }

        $targetNode = Node::query()->find($driverConfig->node_id);

        if (! $targetNode instanceof Node || ! $this->authorizer->allows($caller, $targetNode, 'instance:write')) {
            return $this->authorizationFailed('instance:write', $targetNode, $instance);
        }

        return null;
    }

    private function authorizationFailed(
        string $permission,
        ?Node $servingNode = null,
        ?string $instance = null,
        ?string $message = null,
    ): JsonResponse {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => $message ?? "This node is not authorized for '{$permission}' on the selected instance.",
                'meta' => array_filter([
                    'reason' => 'missing_permission',
                    'missing_permission' => $permission,
                    'serving_node' => $servingNode?->name,
                    'instance' => $instance,
                ]),
            ],
        ], 403);
    }

    private function externalInstanceAuthorizationFailed(?string $instance = null): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'authorization_failed',
                'message' => 'External instances can only be managed by the gateway.',
                'meta' => array_filter([
                    'reason' => 'gateway_only_external_instance',
                    'instance' => $instance,
                ]),
            ],
        ], 403);
    }

    public function subject(): ?Model
    {
        return $this->activitySubject;
    }

    public function effect(): ActivityLogType
    {
        return in_array($this->currentAction, ['list', 'show'], true) ? ActivityLogType::Read : ActivityLogType::Write;
    }

    public function type(): string
    {
        return match ($this->currentAction) {
            'add' => 'api:POST /apps/{app}/instances',
            'remove' => 'api:DELETE /apps/{app}/instances/{instance}',
            'show' => 'api:GET /apps/{app}/instances/{instance}',
            default => 'api:GET /instances',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function properties(): array
    {
        return [];
    }

    public function description(): ?string
    {
        return null;
    }
}

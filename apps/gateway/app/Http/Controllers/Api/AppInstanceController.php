<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Contracts\Loggable;
use App\Data\Apps\AppInstanceRuntimeRequirementsData;
use App\Data\Apps\LaravelCloudAppInstanceDriverConfigData;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\ActivityLogType;
use App\Enums\Apps\AppInstanceDriver;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Project;
use App\Services\Apps\AppInstancePayloads;
use App\Services\Nodes\Access\NodeAccessAuthorizer;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use ValueError;

final class AppInstanceController implements Loggable
{
    private ?Project $activitySubject = null;

    private string $currentAction = 'list';

    public function __construct(
        private readonly AppInstancePayloads $payloads,
        private readonly NodeAccessAuthorizer $authorizer,
        private readonly NodeRoleAssignments $nodeRoleAssignments,
        private readonly WorkspacePlacement $workspacePlacement,
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

        $project = $this->stringInput($request, 'project');
        $query = AppInstance::query()->with(['project.node', 'runtimeMounts']);

        if ($project !== null) {
            $targetProject = Project::query()->where('name', $project)->first();

            if (! $targetProject instanceof Project) {
                return $this->projectNotFound($project);
            }

            $this->activitySubject = $targetProject;
            $query->where('app_id', $targetProject->id);
        }

        $instances = [];

        foreach ($query
            ->orderBy('app_id')
            ->orderBy('name')
            ->get() as $instance) {
            if ($instance instanceof AppInstance) {
                $instances[] = $instance;
            }
        }

        $instances = array_values(array_filter(
            $instances,
            function (AppInstance $instance) use ($caller, $callerIsGateway): bool {
                if ($instance->driver !== AppInstanceDriver::Orbit) {
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

    public function index(string $project, Request $request): JsonResponse
    {
        $app = $project;
        $this->currentAction = 'list';
        $targetApp = $this->resolveApp($app);

        if ($targetApp instanceof JsonResponse) {
            return $targetApp;
        }

        $this->activitySubject = $targetApp;

        if (! $targetApp instanceof Project) {
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

        /** @var list<AppInstance> $instances */
        $instances = $targetApp->instances()->with(['runtimeMounts'])->get()->all();
        $instances = array_values(array_filter(
            $instances,
            function (AppInstance $instance) use ($caller, $callerIsGateway): bool {
                if ($instance->driver !== AppInstanceDriver::Orbit) {
                    return $callerIsGateway;
                }

                $servingNode = $this->workspacePlacement->nodeForInstance($instance);

                return $servingNode instanceof Node
                && $this->authorizer->allows($caller, $servingNode, 'instance:read');
            },
        ));

        return $this->success([
            'project' => $targetApp->name,
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

    public function show(string $project, string $instance, Request $request): JsonResponse
    {
        $app = $project;
        $this->currentAction = 'show';
        $resolved = $this->resolveAppInstance($app, $instance);

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

    public function store(string $project, Request $request): JsonResponse
    {
        $app = $project;
        $this->currentAction = 'add';
        $targetApp = $this->resolveApp($app);

        if ($targetApp instanceof JsonResponse) {
            return $targetApp;
        }

        $this->activitySubject = $targetApp;

        if (! $targetApp instanceof Project) {
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
                "Instance '{$name}' already exists for project '{$targetApp->name}'.",
                [
                    'project' => $targetApp->name,
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

        if ($driver === AppInstanceDriver::LaravelCloud && ! $this->nodeRoleAssignments->nodeIsGateway($caller)) {
            return $this->externalInstanceAuthorizationFailed();
        }

        $driverConfig = match ($driver) {
            AppInstanceDriver::Orbit => $this->orbitDriverConfig($request),
            AppInstanceDriver::LaravelCloud => $this->laravelCloudDriverConfig($request),
        };

        if ($driverConfig instanceof JsonResponse) {
            return $driverConfig;
        }

        $authorization = $this->authorizeOrbitTarget($caller, $driverConfig, $name);

        if ($authorization instanceof JsonResponse) {
            return $authorization;
        }

        $instance = $targetApp
            ->instances()
            ->create([
                'name' => $name,
                'driver' => $driver,
                'adopted' => false,
                'driver_config' => $driverConfig,
                'runtime_requirements' => new AppInstanceRuntimeRequirementsData(php_extensions: $this->phpExtensions(
                    $request,
                )),
            ]);

        return $this->success($this->payloads->withCompatibility($instance));
    }

    public function destroy(string $project, string $instance, Request $request): JsonResponse
    {
        $app = $project;
        $this->currentAction = 'remove';
        $resolved = $this->resolveAppInstance($app, $instance);

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
                'project' => $targetApp->name,
                'instance' => $instance,
            ],
        ]);
    }

    private function driver(Request $request): AppInstanceDriver|JsonResponse
    {
        $value = $this->stringInput($request, 'driver') ?? AppInstanceDriver::Orbit->value;

        try {
            return AppInstanceDriver::from($value);
        } catch (ValueError) {
            return $this->validationFailed(
                'driver',
                'Driver must be one of: orbit, laravel-cloud.',
                [
                    'field' => 'driver',
                    'value' => $value,
                    'allowed' => array_map(
                        static fn (AppInstanceDriver $driver): string => $driver->value,
                        AppInstanceDriver::cases(),
                    ),
                ],
                422,
            );
        }
    }

    private function orbitDriverConfig(Request $request): OrbitAppInstanceDriverConfigData|JsonResponse
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

        return new OrbitAppInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: $path,
            document_root: $documentRoot,
            domain: $this->stringInput($request, 'domain'),
        );
    }

    private function laravelCloudDriverConfig(Request $request): LaravelCloudAppInstanceDriverConfigData|JsonResponse
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

        return new LaravelCloudAppInstanceDriverConfigData(
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

        $normalized = array_values(array_filter(
            array_map(static fn (mixed $extension): ?string => is_string($extension)
                ? strtolower(trim($extension))
                : null, $extensions),
            static fn (?string $extension): bool => $extension !== null && $extension !== '',
        ));

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    /**
     * @return array{0: Project, 1: AppInstance}|JsonResponse
     */
    private function resolveAppInstance(string $app, string $instance): array|JsonResponse
    {
        $targetApp = $this->resolveApp($app);

        if ($targetApp instanceof JsonResponse) {
            return $targetApp;
        }

        if (! $targetApp instanceof Project) {
            return $this->appNotFound($app);
        }

        $targetInstance = $targetApp
            ->instances()
            ->with(['project.node', 'runtimeMounts'])
            ->where('name', $instance)
            ->first();

        if (! $targetInstance instanceof AppInstance) {
            return response()->json([
                'error' => [
                    'code' => 'instance.not_found',
                    'message' => "Instance '{$instance}' was not found for project '{$targetApp->name}'.",
                    'meta' => [
                        'project' => $targetApp->name,
                        'instance' => $instance,
                    ],
                ],
            ], 404);
        }

        return [$targetApp, $targetInstance];
    }

    private function resolveApp(string $selector): Project|JsonResponse|null
    {
        $baseQuery = Project::query()->with(['node', 'instances']);
        $nameMatch = (clone $baseQuery)->where('name', $selector)->first();

        if ($nameMatch instanceof Project) {
            return $nameMatch;
        }

        $matches = $baseQuery
            ->get()
            ->filter(function (Project $app) use ($selector): bool {
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
                'project',
                "Project selector '{$selector}' is ambiguous; use the project name.",
                [
                    'reason' => 'ambiguous_project_selector',
                    'selector' => $selector,
                ],
                422,
            );
        }

        $match = $matches->first();

        return $match instanceof Project ? $match : null;
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

    private function projectNotFound(string $project): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'project.not_found',
                'message' => "Project '{$project}' not found.",
                'meta' => ['project' => $project],
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

    private function authorizeInstance(Request $request, AppInstance $instance, string $permission): ?JsonResponse
    {
        $caller = $this->caller($request);

        if ($caller instanceof JsonResponse) {
            return $caller;
        }

        if ($instance->driver !== AppInstanceDriver::Orbit) {
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
        OrbitAppInstanceDriverConfigData|LaravelCloudAppInstanceDriverConfigData $driverConfig,
        string $instance,
    ): ?JsonResponse {
        if (! $driverConfig instanceof OrbitAppInstanceDriverConfigData) {
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

    public function activityLogType(): ActivityLogType
    {
        return $this->effect();
    }

    public function type(): string
    {
        return match ($this->currentAction) {
            'add' => 'api:POST /projects/{project}/instances',
            'remove' => 'api:DELETE /projects/{project}/instances/{instance}',
            'show' => 'api:GET /projects/{project}/instances/{instance}',
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

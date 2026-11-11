<?php

declare(strict_types=1);

use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Middleware\RequireNodeAccess;
use App\Http\Requests\Tools\ListToolManagersRequest;
use App\Http\Requests\Tools\ListToolsRequest;
use App\Models\Process;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/** @return array<string, array<string, ServingNode>> */
function collection_access_policies(): array
{
    return [
        'ownership-filtered' => [
            // app/Actions/Clusters/ListClustersAction.php:27 filters by accessible member Nodes.
            'cluster:list' => ServingNode::Collection,
            // extensions/conn/src/Http/Controllers/ConnNodesController.php:22 returns the caller's Node only.
            'conn:me' => ServingNode::Collection,
            // extensions/conn/src/Http/Controllers/ConnOperationsController.php:52 checks the Operation's caller.
            'conn:operation:show' => ServingNode::Collection,
            // extensions/conn/src/Domain/ConnThreadProjects.php:32 filters ready defaults by accessibleNodeIds.
            'conn:project:list' => ServingNode::Collection,
            // extensions/conn/src/Actions/CheckConnInstanceNameAction.php:21 uses the default authorized by ConnThreadProjects.php:45.
            'conn:instance:name:check' => ServingNode::Collection,
            // extensions/conn/src/Domain/ConnServerAccess.php:29 filters environment Nodes by accessibleNodeIds.
            'conn:environment:list' => ServingNode::Collection,
            // extensions/conn/src/Http/Controllers/ConnPairingsController.php:37 checks server access, then scopes rows at :41.
            'conn:pairing:list' => ServingNode::Collection,
            // extensions/conn/src/Http/Controllers/ConnPairingsController.php:86 checks both caller and environment ownership.
            'conn:pairing:show' => ServingNode::Collection,
            // app/Actions/Firewall/ListFirewallRulesAction.php:39 uses accessibleNodeIds supplied by FirewallRulesController.php:44.
            'firewall:list' => ServingNode::Collection,
            // app/Actions/Instances/ResolveDirectoryInstanceAction.php:26 checks access and limits Instances to the caller's Node at :29.
            'instance:resolve-directory' => ServingNode::Collection,
            // app/Actions/Instances/ResolveInstanceAction.php:38 checks access to every resolved Instance's Node.
            'instance:resolve' => ServingNode::Collection,
            // app/Actions/Instances/ListInstancesAction.php:28 filters by accessibleNodeIds.
            'instance:list' => ServingNode::Collection,
            // app/Actions/Instances/ListInstanceDeployStepsAction.php:23 reads only the authorized Instance's steps.
            'instance:deploy-step:list' => ServingNode::InstanceOwning,
            // app/Actions/Instances/ListInstanceDeploymentsAction.php:16 scopes deployments to the authorized Instance.
            'instance:deployment:list' => ServingNode::InstanceOwning,
            // app/Http/Controllers/Api/InstanceNodeServiceTargetsController.php:28 scopes attachments to the authorized Instance.
            'instance:node-service:list' => ServingNode::EnvironmentInstanceOwning,
            // app/Actions/Instances/ListInstanceReleasesAction.php:36 reads only the authorized Instance's releases.
            'instance:release:list' => ServingNode::InstanceOwning,
            // app/Domain/Projects/ProjectLifecycleStepStore.php:20 scopes setup steps to the authorized Project.
            'instance:setup-step:list' => ServingNode::ProjectOwning,
            // app/Domain/Projects/ProjectLifecycleStepStore.php:20 scopes teardown steps to the authorized Project.
            'instance:teardown-step:list' => ServingNode::ProjectOwning,
            // app/Actions/Nodes/ListNodesAction.php:24 filters by accessibleNodeIds.
            'node:list' => ServingNode::Collection,
            // app/Actions/Nodes/ListNodeRolesAction.php:22 reads roles of the authorized Node only.
            'node:role:list' => ServingNode::Target,
            // app/Actions/Operations/ListOperationsAction.php:34 filters by accessibleNodeIds.
            'operation:list' => ServingNode::Collection,
            // app/Actions/Processes/ListProcessesAction.php:47 filters both owner types by accessibleNodeIds.
            'process:list' => ServingNode::ProcessCollection,
            // app/Actions/Projects/ListProjectsAction.php:28 filters Projects through accessible Instance Nodes.
            'project:list' => ServingNode::Collection,
            // app/Domain/Projects/ProjectDevelopmentDeployStepStore.php:20 scopes steps to the authorized Project.
            'project:dev-deploy-step:list' => ServingNode::ProjectOwning,
            // extensions/documents/src/Actions/BrowseDocumentsAction.php:30 scopes entries to the authorized Project.
            'project:document:list' => ServingNode::ProjectOwning,
            // extensions/documents/src/Actions/BrowseDocumentsAction.php:52 checks the entry's Project before listing its versions at :55.
            'project:document:version:list' => ServingNode::ProjectOwning,
            // app/Actions/ProjectDefinitions/ListProcessDefinitionsAction.php:16 scopes definitions to the authorized Project.
            'project:process-definition:list' => ServingNode::ProjectOwning,
            // app/Actions/ProjectDefinitions/ListScheduleDefinitionsAction.php:16 scopes definitions to the authorized Project.
            'project:schedule-definition:list' => ServingNode::ProjectOwning,
            // app/Actions/Routes/ListRoutesAction.php:24 filters direct and Cluster ownership by accessibleNodeIds.
            'route:list' => ServingNode::Collection,
            // app/Actions/Schedules/ListSchedulesAction.php:22 filters both target types by accessibleNodeIds.
            'schedule:list' => ServingNode::Collection,
            // app/Actions/Tools/ListToolManagersAction.php:27 scopes managers to the required, authorized node_id.
            'tool:manager:list' => ServingNode::ToolOwning,
            // app/Actions/Tools/ListToolsAction.php:17 scopes Tools to the required, authorized node_id.
            'tool:list' => ServingNode::ToolOwning,
        ],
        'fleet-global by design' => [
            // app/Actions/Activities/ListActivitiesAction.php:23; Activities require Gateway authority.
            'activity:list' => ServingNode::Gateway,
            // extensions/ai-router/src/Actions/ListAiRouterProvidersAction.php:24; provider configuration requires Gateway authority.
            'ai-router:provider:list' => ServingNode::Gateway,
            // extensions/ai-router/src/Actions/ListAiRouterModelsAction.php:26; model configuration requires Gateway authority.
            'ai-router:model:list' => ServingNode::Gateway,
            // extensions/conn/src/Http/Controllers/ConnProfilesController.php:29; docs/reference/conn.md:52 explicitly shares every profile.
            'conn:profile:list' => ServingNode::Collection,
            // extensions/conn/src/Http/Controllers/ConnProfilesController.php:50; shared profile settings follow docs/reference/conn.md:33,52.
            'conn:profile:settings:show' => ServingNode::Collection,
            // app/Actions/DatabaseConnections/ListDatabaseConnectionsAction.php:15; database configuration requires Gateway authority.
            'database:list' => ServingNode::Gateway,
            // app/Actions/DatabaseConnections/ListDatabaseUsersAction.php:16; database user administration requires Gateway authority.
            'database:user:list' => ServingNode::Gateway,
            // app/Actions/DatabaseServers/ListDatabaseServersAction.php:15; database servers require Gateway authority.
            'database:server:list' => ServingNode::Gateway,
            // app/Http/Controllers/Api/ExtensionsController.php:23; fleet extension switches require Gateway authority.
            'extension:list' => ServingNode::Gateway,
            // app/Http/Controllers/Api/GatewayReleasesController.php:28; Gateway release history requires Gateway authority.
            'gateway:release:list' => ServingNode::Gateway,
            // app/Actions/Tasks/ListTaskGroupsAction.php:23; the documented fleet board is shared (docs/reference/tasks.md:493).
            'task:list' => ServingNode::Collection,
            // app/Actions/Tasks/ShowTaskGroupAction.php:21; task detail shares the documented fleet board (docs/reference/tasks.md:493).
            'task:show' => ServingNode::Collection,
            // app/Actions/Tasks/ShowTaskInsightsAction.php:20; board-wide insights use task:list access (docs/reference/tasks.md:1254).
            'task:insights' => ServingNode::Collection,
            // app/Actions/Tasks/ListTaskQuestionsAction.php:24; questions share the documented fleet board (docs/reference/tasks.md:493).
            'task:question:list' => ServingNode::Collection,
            // app/Actions/Tasks/ShowAgentThreadsAction.php:20; task agent sessions require Gateway authority.
            'task:agent:list' => ServingNode::Gateway,
            // app/Http/Controllers/Api/TaskGroupsController.php:224; task comments require Gateway authority (docs/reference/tasks.md:509).
            'task:comment:list' => ServingNode::Gateway,
            // app/Http/Controllers/Api/WebSocketAppsController.php:31; websocket app administration requires Gateway authority.
            'websocket:app:list' => ServingNode::Gateway,
        ],
    ];
}

/** @return array{scope: ?ServingNode, method: ?ReflectionMethod} */
function collection_access_metadata(RoutingRoute $route): array
{
    $controllerClass = $route->getControllerClass();
    if ($controllerClass === null || ! class_exists($controllerClass)) {
        return ['scope' => null, 'method' => null];
    }
    $class = new ReflectionClass($controllerClass);
    $method = $class->getMethod($route->getActionMethod());
    $attribute = $method->getAttributes(RequiresNodeAccess::class)[0] ?? $class->getAttributes(RequiresNodeAccess::class)[0] ?? null;

    return ['scope' => $attribute?->newInstance()->servingNode, 'method' => $method];
}

/**
 * @param  array<RoutingRoute>  $routes
 * @param  array<string, array<string, ServingNode>>|null  $policies
 * @return array<string, string>
 */
function collection_access_violations(array $routes, ?array $policies = null): array
{
    $policies ??= collection_access_policies();
    $declared = [];
    foreach ($policies as $entries) {
        foreach ($entries as $name => $scope) {
            $declared[$name][] = $scope;
        }
    }

    $violations = [];
    $checked = [];
    foreach ($routes as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/') || ! in_array('GET', $route->methods(), true)) {
            continue;
        }
        ['scope' => $scope, 'method' => $method] = collection_access_metadata($route);
        // Cover every GET with collection admission (including detail/lookup reads), plus every :list or index GET.
        // A Collection scope admits any grant; only this named inventory records whether its rows are safe.
        if (! in_array($scope, [ServingNode::Collection, ServingNode::ProcessCollection], true)
            && ! str_ends_with($route->getName() ?? '', ':list') && $route->getActionMethod() !== 'index') {
            continue;
        }

        $name = $route->getName() ?? $route->uri();
        $checked[] = $name;
        if (! in_array(RequireNodeAccess::class, $route->gatherMiddleware(), true) || $scope === null || $method === null) {
            $violations[$name] = 'Missing Node access metadata or middleware.';
        } elseif (! isset($declared[$name])) {
            $violations[$name] = 'Classify this route by name as ownership-filtered or fleet-global by design, with evidence.';
        } elseif (count($declared[$name]) !== 1) {
            $violations[$name] = 'Classify this route in exactly one policy list.';
        } elseif ($scope !== $declared[$name][0]) {
            $violations[$name] = 'The access scope changed; review this route\'s named row policy.';
        } elseif ($scope === ServingNode::ToolOwning) {
            $requestClass = match ($name) {
                'tool:list' => ListToolsRequest::class,
                'tool:manager:list' => ListToolManagersRequest::class,
                default => null,
            };
            $requestType = $method->getParameters()[0]->getType();
            if ($requestClass === null || ! $requestType instanceof ReflectionNamedType || $requestType->getName() !== $requestClass
                || ! in_array('required', (new $requestClass)->rules()['node_id'], true)) {
                $violations[$name] = 'A Tool list must require its owning Node.';
            }
        } else {
            $ownerParameter = match ($scope) {
                ServingNode::Target => 'node',
                ServingNode::InstanceOwning, ServingNode::EnvironmentInstanceOwning => 'instance',
                ServingNode::ProjectOwning => 'project',
                default => null,
            };
            if ($ownerParameter !== null && ! in_array($ownerParameter, $route->parameterNames(), true)) {
                $violations[$name] = 'The owning scope must bind its target in the route.';
            }
        }
    }

    foreach (array_diff(array_keys($declared), $checked) as $name) {
        $violations[$name] = 'Remove this stale policy: its covered GET route no longer exists.';
    }

    return $violations;
}

final class CollectionAccessReviewController
{
    /** @return Collection<int, Process> */
    #[RequiresNodeAccess(ServingNode::Collection)]
    public function unfiltered(): Collection
    {
        return Process::query()->get();
    }

    /** @return Collection<int, Process> */
    public function index(): Collection
    {
        return Process::query()->get();
    }
}

it('requires exactly one named row policy on every covered GET route', function (): void {
    expect(collection_access_violations(Route::getRoutes()->getRoutes()))->toBe([]);
});

it('rejects a new unfiltered Collection route even with access metadata and an arbitrary action name', function (): void {
    Route::get('api/v1/review-unguarded', [CollectionAccessReviewController::class, 'unfiltered'])
        ->middleware(RequireNodeAccess::class)->name('review:unguarded');

    expect(collection_access_violations(Route::getRoutes()->getRoutes()))->toBe([
        'review:unguarded' => 'Classify this route by name as ownership-filtered or fleet-global by design, with evidence.',
    ]);
});

it('rejects a GET list with missing access metadata', function (): void {
    Route::get('api/v1/review-missing-metadata', [CollectionAccessReviewController::class, 'index'])
        ->middleware(RequireNodeAccess::class)->name('review:missing:list');

    expect(collection_access_violations(Route::getRoutes()->getRoutes()))->toBe([
        'review:missing:list' => 'Missing Node access metadata or middleware.',
    ]);
});

it('rejects a stale policy when its covered route no longer exists', function (): void {
    $routes = array_values(array_filter(Route::getRoutes()->getRoutes(), static fn (RoutingRoute $route): bool => $route->getName() !== 'process:list'));

    expect(collection_access_violations($routes))->toBe([
        'process:list' => 'Remove this stale policy: its covered GET route no longer exists.',
    ]);
});

it('rejects a route classified in both policy lists', function (): void {
    $policies = collection_access_policies();
    $policies['fleet-global by design']['process:list'] = ServingNode::ProcessCollection;

    expect(collection_access_violations(Route::getRoutes()->getRoutes(), $policies))->toBe([
        'process:list' => 'Classify this route in exactly one policy list.',
    ]);
});

<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Operations\OperationStore;
use App\Domain\Operations\RoutePublicationHolds;
use App\Domain\Routes\RouteStatus;
use App\Models\Operation;
use App\Models\Route;
use App\Support\ValidatedData;

/**
 * A container clone's address during its create (docs/reference/development-containers.md#the-address-during-the-create). The create
 * publishes it beside the copy only when its admission takes the Route Nodes while they are free; it never waits for them. The Gateway
 * frees them when the run's `clone-route` step has succeeded, with the Route active in the same transaction, so no step calls the
 * Gateway and the next Route writer's render keeps the site.
 */
final readonly class ClonePublication
{
    /** The create's result field: `published`, `deferred` or `failed`; absent without a Route. */
    public const string Field = 'route_publication';

    /** A clone's create that captured its Route publication: it takes the Route Nodes only when they are free. */
    public static function takesFreeNodesOnly(Operation $operation): bool
    {
        return $operation->kind === 'instance.create' && self::clone($operation) && isset($operation->input['route_create']);
    }

    /**
     * A step of the clone create's publication: its job `publication`, or a child run's step, which only `clone-route-csr` and
     * `clone-route` start in a clone's create.
     *
     * @param  array<string, mixed>  $event
     */
    public static function ownsStep(Operation $operation, array $event): bool
    {
        return self::takesFreeNodesOnly($operation) && (isset($event['parentRunId']) || ($event['jobId'] ?? null) === 'publication');
    }

    /**
     * A Route Node is held, or a waiting Route write needs one: the create drops its publication and starts at once. Its Route stays
     * reserved and unpublished, so its completion captures the Route and publishes it (StartInstanceCompleteAction).
     */
    public function defer(Operation $operation): void
    {
        $input = array_diff_key($operation->input, ['route_create' => true, 'route_lifecycle' => true, 'certificates' => true, 'execution_secrets' => true, 'mode' => true]);
        $input['participant_node_ids'] = [ValidatedData::integer($input['node_id'])];
        $input['direct_provisioning'] = [...ValidatedData::object($input['direct_provisioning']), 'route' => null];
        app(OperationStore::class)->change($operation, ['input' => $input]);
    }

    /**
     * What the create's result says of the address, from its admitted input and the run's own word.
     *
     * @param  array<string, mixed>  $result
     */
    public static function outcome(Operation $operation, array $result): ?string
    {
        if (($operation->input['route_id'] ?? null) === null || ! self::clone($operation)) {
            return null;
        }
        if (! isset($operation->input['route_create'])) {
            return 'deferred';
        }

        return ($result[self::Field] ?? null) === 'published' ? 'published' : 'failed';
    }

    /**
     * The run's `clone-route` step succeeded: the Route turns active with its sites published and the create's Route Nodes are freed,
     * in the caller's transaction. The create's terminal projection repeats the activation when this event was lost.
     */
    public function published(Operation $operation): void
    {
        if (! self::takesFreeNodesOnly($operation)) {
            return;
        }
        $this->activate($operation);
        app(RoutePublicationHolds::class)->releasePublished($operation);
    }

    /** A Route that another write has taken since the admission, such as a `route:destroy` that marked it retiring, stays as it is. */
    public function activate(Operation $operation): void
    {
        $route = $this->route($operation);
        if ($route !== null && in_array($route->status, [RouteStatus::Pending, RouteStatus::Failed, RouteStatus::Active], true)) {
            $route->update(['status' => RouteStatus::Active, 'sites_published' => true, 'failed_step' => null, 'error_code' => null]);
        }
    }

    /** The publication failed in the create: the Route says so, and its completion publishes it again. A published Route stays as it is. */
    public function failed(Operation $operation): void
    {
        $route = $this->route($operation);
        if ($route !== null && ! $route->sites_published && in_array($route->status, [RouteStatus::Pending, RouteStatus::Failed], true)) {
            $route->update(['failed_step' => 'native-create', 'error_code' => 'instance.create_route_failed']);
        }
    }

    private function route(Operation $operation): ?Route
    {
        return Route::query()->whereKey(ValidatedData::integer($operation->input['route_id']))->lockForUpdate()->first();
    }

    private static function clone(Operation $operation): bool
    {
        $plan = $operation->input['direct_provisioning'] ?? null;
        $container = is_array($plan) ? ($plan['container'] ?? null) : null;

        return is_array($container) && ($container['clone'] ?? null) !== null;
    }
}

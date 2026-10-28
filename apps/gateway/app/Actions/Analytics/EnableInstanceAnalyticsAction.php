<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Data\Routes\RouteData;
use App\Domain\Analytics\AnalyticsTrackingHosts;
use App\Domain\Analytics\AnalyticsTrackingUpstream;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RouteStateResolver;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class EnableInstanceAnalyticsAction
{
    public function __construct(
        private ShowInstanceAnalyticsAction $show,
        private StartInstanceAnalyticsOperationAction $intents,
        private RouteStateResolver $state,
        private InstanceEnvironmentOperationLock $operations,
        private RecordEventBroadcaster $broadcaster,
    ) {}

    /** @param list<string> $hosts The exact host set; empty means the default host. */
    public function execute(Instance $instance, array $hosts): Operation
    {
        $result = $this->operations->run(
            [$instance->id],
            fn (): Operation => DB::transaction(fn (): Operation => $this->executeOwned($instance, $hosts)),
        );

        return $result;
    }

    /** @param list<string> $hosts */
    private function executeOwned(Instance $instance, array $hosts): Operation
    {
        if (! AnalyticsTrackingUpstream::node() instanceof Node) {
            throw new ResourceOperationException(
                errorCode: 'analytics.role_missing',
                message: 'A tracking host needs a Node with an active analytics role.',
                status: 409,
            );
        }

        $owner = $this->instanceRoute($instance);

        if ($owner->cluster_id !== null) {
            $this->state->assertRouter((int) $owner->cluster_id);
        }

        $hosts = $hosts === [] ? [AnalyticsTrackingHosts::defaultFor($owner->domain)] : $hosts;
        $current = $this->show->trackingRoutes($instance)->keyBy('domain');

        foreach ($hosts as $host) {
            if (! $current->has($host) && Route::query()->where('domain', $host)->exists()) {
                throw $this->hostTaken($host);
            }
        }

        $desired = [];
        foreach ($hosts as $host) {
            $route = $current->get($host) ?? $this->create($instance, $owner, $host);
            $desired[] = $route;
        }

        $retired = array_values($current->reject(static fn (Route $route): bool => in_array($route->domain, $hosts, true))->all());

        return $this->intents->execute($instance, $desired, $retired);

    }

    /**
     * A tracking host is served wherever the Instance's own domain is served, so it mirrors that
     * Route: a cluster-scoped public Route reaches the internet through the Ingress and the Router,
     * and a node-scoped private Route is served by the instance's own Node behind whatever edge
     * already fronts it.
     */
    private function instanceRoute(Instance $instance): Route
    {
        $route = $instance->unsetRelation('routes')->authoritativeRoute();

        if (! $route instanceof Route || $route->domain === '') {
            throw new ResourceOperationException(
                errorCode: 'analytics.domain_required',
                message: 'A tracking host needs an Instance that already serves a domain.',
                status: 422,
            );
        }

        return $route;
    }

    private function create(Instance $instance, Route $owner, string $host): Route
    {
        try {
            $route = DB::transaction(static function () use ($instance, $owner, $host): Route {
                $route = Route::query()->create([
                    'kind' => RouteKind::AnalyticsTracking,
                    'project_id' => null,
                    'node_id' => $owner->node_id,
                    'cluster_id' => $owner->cluster_id,
                    'generation_basis_node_id' => null,
                    'domain' => $host,
                    'provenance' => RouteProvenance::Explicit,
                    'publication' => $owner->publication,
                    'status' => RouteStatus::Pending,
                    'failed_step' => null,
                    'error_code' => null,
                ]);
                $route->analyticsTracking()->create(['instance_id' => $instance->id]);

                return $route;
            });
        } catch (QueryException $exception) {
            throw $this->hostTaken($host, $exception);
        }

        $this->broadcaster->broadcast(
            RecordEventType::RouteCreated,
            $route->id,
            RouteData::fromModel($route)->toArray(),
        );

        return $route;
    }

    private function hostTaken(string $host, ?Throwable $previous = null): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'analytics.host_taken',
            message: "Host [{$host}] is already the domain of another Route.",
            status: 409,
            previous: $previous,
        );
    }
}

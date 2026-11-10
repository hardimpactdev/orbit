<?php

declare(strict_types=1);

namespace App\Domain\InstanceMigration;

use App\Domain\Routes\NativeRouteMutationInput;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Route;
use App\Models\RouteTarget;
use Closure;
use Illuminate\Support\Collection;

/**
 * The Route publication of a move: the checkout's site and PHP-FPM pool before, the container's site after, with the restore side
 * that puts the checkout's back. The Route keeps its domain, its Node and its certificate, so the publication signs nothing and
 * writes no `.env`: the move copies the checkout's own.
 *
 * @phpstan-import-type CapturedRoute from NativeRouteMutationInput
 */
final readonly class InstanceMigrationRoute
{
    public function __construct(private NativeRouteMutationInput $mutations, private DevelopmentSiteRepository $sites) {}

    /**
     * The Node's sites and the Route as they serve the checkout. Read before the runtime row exists, which turns the site.
     *
     * @return array{sites: Collection<int, DevelopmentSite>, route: CapturedRoute}
     */
    public function before(Route $route): array
    {
        return ['sites' => $this->sites->all(), 'route' => $this->withoutCertificates($this->mutations->route($this->loaded($route), environment: false))];
    }

    /**
     * @param  array{sites: Collection<int, DevelopmentSite>, route: CapturedRoute}  $before
     * @return array{route_mutations: array<string, mixed>, participant_node_ids: list<int>, execution_secrets: array<string, array{vault: string, key: string}>}
     */
    public function capture(Instance $instance, Route $route, InstanceRuntime $runtime, array $before): array
    {
        $moving = clone $instance;
        $moving->setRelation('runtime', $runtime);
        $candidate = clone $this->loaded($route);
        $candidate->setRelation('targets', $candidate->targets->map(static function (RouteTarget $row) use ($moving): RouteTarget {
            $target = clone $row;

            return $target->instance_id === $moving->id ? $target->setRelation('instance', $moving) : $target;
        }));
        $desired = [$this->withoutCertificates($this->mutations->route($candidate, [$route->id => $candidate], environment: false))];
        // The new site is staged as it will serve: the Node and the domain stay, so no DNS record is withdrawn between the two.
        $capture = $this->mutations->capture([$before['route']], $desired, $desired, [$before['route']], $before['sites'], $before['sites'],
            [$instance->node_id], static fn (Closure $render): array => $render());

        return ['route_mutations' => $capture['route_mutations'], 'participant_node_ids' => $capture['participant_node_ids'],
            'execution_secrets' => $capture['execution_secrets']];
    }

    private function loaded(Route $route): Route
    {
        return $route->loadMissing(['targets.instance.node', 'targets.instance.project', 'cluster.routerAssignment.node', 'cluster.ingressAssignment.node']);
    }

    /**
     * @param  CapturedRoute  $captured
     * @return CapturedRoute
     */
    private function withoutCertificates(array $captured): array
    {
        $captured['intent']['certificates'] = [];

        return $captured;
    }
}

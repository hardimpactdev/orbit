<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Actions\Integrations\StartIntegrationOperationAction;
use App\Domain\Integrations\IntegrationPlan;
use App\Infrastructure\Routes\IngressSiteRepository;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Route;

final readonly class StartInstanceAnalyticsOperationAction
{
    public function __construct(private StartIntegrationOperationAction $operations, private IngressSiteRepository $sites) {}

    /** @param list<Route> $desired
     * @param  list<Route>  $retired
     */
    public function execute(Instance $instance, array $desired, array $retired, bool $remove = false): Operation
    {
        $plan = new IntegrationPlan($instance->node_id);
        $nodes = [];
        foreach (['publish' => $desired, 'remove' => $retired] as $action => $routes) {
            foreach ($routes as $route) {
                $host = $this->sites->routerNode($route);
                $nodes[] = $host->id;
                $plan->child('tracking-'.$action.'-'.$route->id, 'route.analytics_'.$action, $host->id, ['route_id' => $route->id]);
            }
        }
        $plan->receipt($remove ? 'absent' : 'active');

        return $this->operations->execute($remove ? 'analytics.instance_disable' : 'analytics.instance_enable', 'instance_analytics', $instance->id, $instance->node_id,
            ['instance_id' => $instance->id, 'route_node_ids' => array_values(array_unique($nodes))], $plan);
    }
}

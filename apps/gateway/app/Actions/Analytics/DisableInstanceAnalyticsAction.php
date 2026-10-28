<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Operations\AwaitOperation;
use App\Infrastructure\Routes\IngressSiteRepository;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Route;
use Illuminate\Support\Facades\DB;

final readonly class DisableInstanceAnalyticsAction
{
    public function __construct(private ShowInstanceAnalyticsAction $show, private StartInstanceAnalyticsOperationAction $intents,
        private InstanceEnvironmentOperationLock $operations, private IngressSiteRepository $sites, private StartOperationAction $starts, private AwaitOperation $await) {}

    public function execute(Instance $instance): Operation
    {
        return $this->operations->run([$instance->id], fn (): Operation => DB::transaction(fn (): Operation => $this->intents->execute($instance, [], array_values($this->show->trackingRoutes($instance)->all()), true)));
    }

    public function removeRoute(Route $route): void
    {
        $node = $this->sites->routerNode($route);
        $this->await->execute($this->starts->execute('route.analytics_remove', 'route', $route->id, $node->id, ['route_id' => $route->id], 'integration', null));
    }
}

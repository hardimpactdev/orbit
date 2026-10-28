<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Data\Instances\InstanceSmokeContextData;
use App\Data\Instances\InstanceSmokeSelectionData;
use App\Domain\Instances\Deployment\ProductionDeployment;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Route;

final readonly class ReadInstanceSmokeContextAction
{
    public function __construct(private ProductionDeployment $deployment) {}

    public function ownership(Instance $instance): InstanceSmokeContextData
    {
        $instance->refresh()->load('node.roles', 'project', 'routes');
        $roles = $instance->node->roles->where('status', LifecycleStatus::Active)->pluck('role');
        $production = $instance->status === InstanceState::Active && $instance->node->status === LifecycleStatus::Active
            && $instance->placedOnAppProd() && $roles->contains(RoleName::AppProd) && ! $roles->contains(RoleName::AppDev);
        $urls = $instance->routes->filter(static fn (Route $route): bool => $route->isApp()
                && $route->status === RouteStatus::Active
                && $route->publication === RoutePublication::Public
                && $route->project_id === $instance->project_id)
            ->map(static fn (Route $route): string => 'https://'.$route->domain)->unique()->sort()->values()->all();

        return new InstanceSmokeContextData($instance->id, $instance->project_id, $instance->project->repository_url,
            $production, array_values($urls), null, []);
    }

    public function execute(Instance $instance): InstanceSmokeContextData
    {
        $ownership = $this->ownership($instance);
        $retained = [];
        $selected = null;
        if ($ownership->production) {
            $retained = $this->deployment->releases($instance)->releases;
            $actual = $this->deployment->selected($instance);
            if ($actual !== null && in_array($actual->name, $retained, true)) {
                $history = InstanceDeployment::query()->where('instance_id', $instance->id)->where('status', 'succeeded')
                    ->whereIn('kind', ['deploy', 'rollback'])
                    ->where('release', $actual->name)->where('commit', $actual->commit)
                    ->latest('id')->first();
                if ($history !== null && preg_match('/\A[0-9a-f]{40}\z/D', $actual->commit) === 1) {
                    $selected = new InstanceSmokeSelectionData($history->id, $actual->commit, $actual->name);
                }
            }
        }

        return new InstanceSmokeContextData(
            $instance->id, $instance->project_id, $instance->project->repository_url,
            $ownership->production, $ownership->baseUrls, $selected, $retained,
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Route;

final class ProductionSmokeRoute
{
    public static function create(Instance $instance): Route
    {
        $cluster = Cluster::query()->create(['name' => 'smoke', 'state' => 'active']);
        $instance->node->update(['cluster_id' => $cluster->id]);
        $route = Route::query()->create([
            'project_id' => $instance->project_id,
            'cluster_id' => $cluster->id,
            'domain' => 'smoke.example.test',
            'status' => 'pending',
            'publication' => 'public',
            'provenance' => 'explicit',
        ]);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => 'active']);

        return $route;
    }
}

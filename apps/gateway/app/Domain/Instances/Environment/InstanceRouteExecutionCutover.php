<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Project;
use App\Models\Route;
use App\Support\ValidatedData;

/** Refuse retired production Route execution before any database or Node effect. */
final class InstanceRouteExecutionCutover
{
    /** @param list<int> $additionalIds */
    public static function assertRoute(Route $route, array $additionalIds = []): void
    {
        self::assertInstances([...ValidatedData::integerList($route->targets()->pluck('instance_id')->all()), ...$additionalIds]);
    }

    public static function assertProject(Project $project): void
    {
        self::assertInstances(ValidatedData::integerList($project->instances()->pluck('id')->all()));
    }

    /** @param list<int> $instanceIds */
    public static function assertInstances(array $instanceIds): void
    {
        foreach (Instance::query()->with('node')->whereIn('id', $instanceIds)->get() as $instance) {
            if ($instance->placedOnAppProd()) {
                throw new ResourceOperationException('operation.kind_unavailable',
                    'Production Route changes require the converted Route Operation and its environment child.', 503);
            }
        }
    }
}

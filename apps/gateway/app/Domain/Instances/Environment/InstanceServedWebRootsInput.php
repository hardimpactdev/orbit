<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Routes\RouteWebRoot;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\ProductionApplicationPaths;
use App\Models\Instance;
use App\Models\Operation;

/** Pure admitted resource validation and fixed primitive input. */
final readonly class InstanceServedWebRootsInput
{
    public function __construct(private InstanceRouteOwnership $ownership) {}

    /** @return array<string, mixed>|null */
    public function forOperation(Operation $operation): ?array
    {
        $route = $this->ownership->route($operation) ?? throw $this->invalid();
        $instance = Instance::query()->with(['project', 'node'])->findOrFail($operation->target_id);
        $user = $instance->production_user;
        $home = $instance->production_home;
        $root = $instance->root ?? $instance->project->root;
        if (! $instance->placedOnAppProd() || ! is_string($user) || ! is_string($home) || $home !== '/home/'.$user || ! is_string($root)) {
            throw $this->invalid();
        }
        $activating = $this->ownership->preparingRoute($operation);
        $served = array_map(static fn (array $application): array => ['web_root' => $application['web_root'], 'directory' => $application['directory']], RouteWebRoot::servedApplications($instance, $activating));
        if ($activating !== null && RouteWebRoot::poolSuffix($instance, $activating->web_root) !== null
            && ! array_any($served, static fn (array $application): bool => $application['web_root'] === $activating->web_root)) {
            $served[] = ['web_root' => $activating->web_root, 'directory' => RouteWebRoot::relativeDirectory($activating->web_root)];
        }

        return $served === [] ? null : [
            'repository' => $instance->project->repository_url, 'user' => $user, 'home' => $home, 'instance' => $instance->id,
            'relative_root' => $root, 'application_suffix' => ProductionApplicationPaths::suffix($root), 'served' => $served,
        ];
    }

    private function invalid(): ResourceOperationException
    {
        return new ResourceOperationException('operation.execution_forbidden', 'The Route parent does not own this production web root.', 422);
    }
}

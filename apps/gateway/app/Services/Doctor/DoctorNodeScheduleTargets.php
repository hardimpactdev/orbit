<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Models\Instance;
use App\Models\Node;
use App\Models\Schedule;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class DoctorNodeScheduleTargets
{
    public function __construct(
        private WorkspacePlacement $workspacePlacement,
    ) {}

    /**
     * @return Builder<Schedule>
     */
    public function expectedFor(Node $node): Builder
    {
        /** @var Builder<Schedule> $query */
        $query = Schedule::query();
        $instanceIds = $this->instanceIdsFor($node);

        return $query
            ->where('enabled', true)
            ->where('status', 'expected')
            ->where(static function (Builder $query) use ($node, $instanceIds): void {
                $query
                    ->where('node_id', $node->id)
                    ->orWhereIn('instance_id', $instanceIds);
            });
    }

    /**
     * @return list<int>
     */
    private function instanceIdsFor(Node $node): array
    {
        /** @var Collection<int, Instance> $instances */
        $instances = Instance::query()
            ->with(['app.node', 'app.instances'])
            ->get();

        /** @var list<int> $instanceIds */
        $instanceIds = $instances
            ->filter(
                fn (Instance $instance): bool => (
                    $this->workspacePlacement->nodeForInstance($instance)?->id === $node->id
                ),
            )
            ->pluck('id')
            ->all();

        return $instanceIds;
    }
}

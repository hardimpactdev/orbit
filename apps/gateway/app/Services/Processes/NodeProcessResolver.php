<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Enums\Nodes\NodeRoleName;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Workspaces\WorkspacePlacement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final readonly class NodeProcessResolver
{
    private const array WORKSPACE_OWNER_TYPES = [Workspace::class, 'workspace'];

    public function __construct(
        private NodeRoleAssignments $nodeRoleAssignments,
        private WorkspacePlacement $workspacePlacement,
    ) {}

    /**
     * @param  list<string>  $relations
     * @return Collection<int, Process>
     */
    public function forNode(Node $node, array $relations = []): Collection
    {
        /** @var list<int> $placedInstanceIds */
        $placedInstanceIds = [];

        foreach (Instance::query()->with('app')->get() as $instance) {
            if ($this->workspacePlacement->nodeForInstance($instance)?->is($node) !== true) {
                continue;
            }

            $placedInstanceIds[] = $instance->id;
        }

        /** @var Collection<int, Process> */
        return $this
            ->query($node, $placedInstanceIds)
            ->with($relations)
            ->get()
            ->filter(fn (Process $process): bool => $this->belongsToNode($process, $node))
            ->values();
    }

    /**
     * @param  list<int>  $placedInstanceIds
     * @return Builder<Process>
     */
    private function query(Node $node, array $placedInstanceIds): Builder
    {
        /** @var Builder<Process> $query */
        $query = Process::query()->where(static function (Builder $builder) use ($node, $placedInstanceIds): void {
            $builder->where('node_id', $node->id);

            if ($placedInstanceIds !== []) {
                $builder->orWhereIn('instance_id', $placedInstanceIds);
            }
        });

        if ($this->nodeRoleAssignments->nodeHasActiveRole($node, NodeRoleName::AppProduction->value)) {
            $query->whereNotIn('owner_type', self::WORKSPACE_OWNER_TYPES);
        }

        return $query;
    }

    private function belongsToNode(Process $process, Node $node): bool
    {
        $process->loadMissing(['owner', 'instance']);

        if ($process->owner instanceof Node) {
            return $process->owner->is($node);
        }

        if ($process->owner instanceof Workspace) {
            $placed = $this->workspacePlacement->nodeForWorkspace($process->owner);

            return $placed instanceof Node && $placed->is($node);
        }

        if ($process->instance instanceof Instance) {
            $placed = $this->workspacePlacement->nodeForInstance($process->instance);

            return $placed instanceof Node && $placed->is($node);
        }

        return $process->node_id === $node->id;
    }
}

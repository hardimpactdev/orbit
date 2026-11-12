<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\GitHub\RepositoryReadContext;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\SourceControl\GitRemoteBranchHeads;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Support\ValidatedData;

/** Opt-in branch admission; old creates do not enter this path. */
final readonly class DevelopmentInstanceBranchAdmission
{
    public function __construct(private GitRemoteBranchHeads $heads, private NodeAccessAuthorizer $access) {}

    public function prepare(Project $project, string $name, InstanceBranchSelection $selection, ?string $caller): InstanceBranchSelection
    {
        $existing = Instance::query()->where('project_id', $project->id)->where('name', $name)->first();
        if ($existing !== null) {
            return $this->retry($existing, $selection, $caller);
        }
        $heads = $this->heads->heads($project, new RepositoryReadContext);
        // Occupancy wins over remote existence, including the default branch. Rechecked under the create lock.
        $this->assertAvailable($project, $selection, null, $caller);
        $source = $selection->base ?? $selection->target;
        if (! isset($heads[$source])) {
            throw new ResourceOperationException('branch.not_found', 'Remote branch does not exist.', 422,
                details: ['field' => $selection->mode === 'new' ? 'branch.from' : 'branch.name']);
        }
        if ($selection->mode === 'new' && isset($heads[$selection->target])) {
            throw new ResourceOperationException('branch.exists', 'A branch with this name already exists.', 422,
                details: ['field' => 'name', 'branch' => $selection->target]);
        }

        return new InstanceBranchSelection($selection->mode, $selection->target, $selection->base, $heads[$source]);
    }

    /** Called inside create's IMMEDIATE SQLite transaction, before any reservation. */
    public function reserve(Project $project, Project $observed, InstanceBranchSelection $selection, ?Instance $existing, ?string $caller): InstanceBranchSelection
    {
        if ($project->repository_url !== $observed->repository_url || $project->source_access !== $observed->source_access) {
            throw new ResourceOperationException('source.head_unavailable', 'The repository changed during admission.', 502);
        }
        if ($existing !== null) {
            $selection = $this->retry($existing, $selection, $caller);
        }
        $this->assertAvailable($project, $selection, $existing, $caller);

        return $selection;
    }

    private function retry(Instance $instance, InstanceBranchSelection $selection, ?string $caller): InstanceBranchSelection
    {
        $operation = Operation::query()->where('kind', 'instance.create')->where('target_type', 'instance')
            ->where('target_id', $instance->id)->latest('id')->first();
        if ($operation?->triggered_by !== $caller) {
            throw new ResourceOperationException('instance.name_taken', 'Another caller holds this Instance name.', 409);
        }
        $stored = $operation?->input['branch_selection'] ?? null;
        if (! is_array($stored)
            || ! $selection->sameIntent(InstanceBranchSelection::fromArray(ValidatedData::object($stored)))) {
            throw new ResourceOperationException('instance.placement_conflict', 'Instance placement is immutable.', 409);
        }

        return InstanceBranchSelection::fromArray(ValidatedData::object($stored));
    }

    private function assertAvailable(Project $project, InstanceBranchSelection $selection, ?Instance $existing, ?string $caller): void
    {
        foreach (Instance::query()->where('project_id', $project->id)->orderBy('id')->get() as $holder) {
            if ($holder->id === $existing?->id || IntendedInstanceBranch::forInstance($holder, $project->default_branch) !== $selection->target) {
                continue;
            }
            $node = $caller !== null && preg_match('/\Anode:([0-9]+)\z/D', $caller, $matches) === 1
                ? Node::query()->find((int) $matches[1]) : null;
            $visible = $node !== null && $this->access->allows($node, $holder->node);
            throw new InstanceBranchInUse('branch.in_use', 'Branch is held by an Instance.', 409,
                details: $visible ? ['instance' => ['id' => $holder->id, 'name' => $holder->name]] : []);
        }
    }
}

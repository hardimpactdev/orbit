<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Actions\Operations\StartOperationAction;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Nodes\SwampNode;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

/** Captures Gateway state. Native workflows own scanning and schedule selection. */
final readonly class ProjectMaintenanceIntent
{
    public function __construct(private StartOperationAction $start, private OperationIdempotency $idempotency) {}

    /** `open` asks an inspection to admit its update group; the Schedule always asks. */
    public function start(Project $project, string $mode, ?string $requestId = null, bool $open = false): Operation
    {
        if (! in_array($mode, ['detect', 'routine', 'observe'], true)) {
            throw new ResourceOperationException('project.maintenance_invalid', 'Unknown Project maintenance request.', 422);
        }
        $kind = 'project.'.$mode;
        if (($existing = $this->idempotency->replay($kind)) !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($project, $kind, $requestId, $open): Operation {
            $project = Project::query()->lockForUpdate()->findOrFail($project->id);
            if (ProjectMaintenanceRequirements::missing($project) !== []) {
                throw new ResourceOperationException('project.maintenance_unavailable', 'Project maintenance requires its GitHub App repository and configured check.', 422);
            }
            if (! $project->maintenance) {
                throw new ResourceOperationException('project.maintenance_disabled', 'Dependency maintenance is off for this Project; turn it on with project:maintenance:enable.', 409);
            }
            $groups = $kind === 'project.detect' ? collect() : Task::topLevel()->where('project_id', $project->id)->orderBy('id')->get([
                'id', 'project_id', 'status', 'orchestration', 'brief', 'pr_url', 'watched_pr_url', 'watched_pr_state', 'taskable_id', 'maintenance',
            ]);
            $urls = [];
            if ($kind === 'project.observe') {
                $repository = GitHubRepository::fromOrigin($project->repository_url);
                foreach ($groups as $group) {
                    if (in_array($group->status->value, ['completed', 'cancelled', 'failed'], true)) {
                        continue;
                    }
                    foreach (array_filter([$group->pr_url, $group->watched_pr_url]) as $url) {
                        if ($repository?->pullRequestNumber($url) === null) {
                            throw new ResourceOperationException('project.pr_identity_mismatch', 'A watched pull request must belong to this Project.', 422);
                        }
                        $urls[] = $url;
                    }
                }
                $urls = array_values(array_unique($urls));
                if (count($urls) > 128) {
                    throw new ResourceOperationException('project.maintenance_too_large', 'Observe at most 128 watched pull requests in one Project.', 422);
                }
            }

            return $this->start->execute($kind, 'project', $project->id, SwampNode::id(), [
                'project' => ['id' => $project->id, 'repository' => $project->repository_url,
                    'default_branch' => $project->default_branch, 'check' => $project->task_check],
                // An inspection reads no group; the Gateway checks open work from its own records when it admits one.
                ...($kind === 'project.detect' ? ['open' => $open] : ['groups' => $groups->toArray()]),
                ...($kind === 'project.observe' ? ['pr_urls' => $urls] : []), 'admitted_at' => now()->toIso8601ZuluString(),
            ], 'project-maintenance', $requestId);
        });
    }
}

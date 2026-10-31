<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Instances\ReadInstanceSmokeContextAction;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;

/**
 * Local target ownership and publication intent. Swamp owns operational policy.
 *
 * @phpstan-type Intent array{project_id:int,group_id:int,publish_operation_id:int,repository:string,default_branch:string,smoke_workflow:string,pr_number:int,head_sha:string,token:array{'$secret':string,id:int}}
 */
final readonly class DependencyBatchMergeGuard
{
    public function __construct(private ReadInstanceSmokeContextAction $context) {}

    /** @return Intent */
    public function lineage(Task $group, int $publishId, string $head): array
    {
        $group->refresh()->load('project', 'tasks');
        $project = $group->project;
        $policy = config('dependency_merge.projects.'.$project->id);
        $this->check(is_array($policy) && ($policy['enabled'] ?? false) === true, 'dependency_merge.disabled');
        $workflow = $policy['smoke_workflow'] ?? null;
        $this->check(is_string($workflow) && preg_match('/\A\.github\/workflows\/[A-Za-z0-9_-]+\.ya?ml\z/D', $workflow) === 1, 'dependency_merge.smoke_missing');
        $this->check(is_string($project->default_branch) && $project->default_branch !== '', 'dependency_merge.default_branch_changed');
        $this->check(in_array($project->type->value, ['laravel-app', 'symfony-app'], true), 'dependency_merge.app_required');
        $instance = Instance::query()->where('project_id', $project->id)->whereKey($policy['instance_id'] ?? 0)->first();
        $ownership = $instance === null ? null : $this->context->ownership($instance);
        $this->check($ownership?->production === true && $ownership->baseUrls !== [], 'dependency_merge.host_required');
        $this->check($group->parent_id === null && $group->orchestration === TaskOrchestration::External
            && ! in_array($group->status, [TaskGroupStatus::Completed, TaskGroupStatus::Cancelled, TaskGroupStatus::Failed], true)
            && ! $group->assistance_requested && $group->tasks->count() === 1, 'dependency_merge.lineage_invalid');
        $child = $group->tasks->sole();
        $services = config('dependency_detection.services_node_id');
        $this->check((is_int($services) || is_string($services)) && $group->created_by_node_id !== null && (string) $group->created_by_node_id === (string) $services
            && ! $child->assistance_requested && ! in_array($child->status, [TaskStatus::Failed, TaskStatus::Cancelled], true)
            && array_any($child->deliverables ?? [], static fn (array $item): bool => ($item['id'] ?? null) === 'package' && ($item['type'] ?? null) === 'review')
            && preg_match('/^Swamp schedule: batch-[a-f0-9]{64}$/m', $group->brief ?? '') === 1, 'dependency_merge.batch_required');
        $publish = Operation::query()->where('kind', OperationKinds::TasksPublish)->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)->find($publishId);
        $result = $publish === null ? [] : ($publish->result ?? []);
        $repository = GitHubRepository::fromOrigin($project->repository_url);
        $number = $result['pull_request_number'] ?? null;
        $url = $result['pull_request_url'] ?? null;
        $check = TaskCheck::query()->where('task_id', $child->id)->whereKey($publish?->input['check'] ?? 0)->first();
        $this->check($repository !== null && $publish?->status->value === 'succeeded'
            && $check?->kind === TaskCheckKind::Handoff && $check->status === TaskCheckStatus::Passed && $check->commit_sha === $head
            && ($result['commit'] ?? null) === $head && ($result['branch'] ?? null) === 'task-'.$group->id
            && is_int($number) && $number > 0 && $url === 'https://github.com/'.$repository->owner.'/'.$repository->name.'/pull/'.$number
            && $group->pr_url === $url && $group->watched_pr_url === $url && $group->watched_pr_number === $number,
            'dependency_merge.publication_invalid');

        return ['project_id' => $project->id, 'group_id' => $group->id, 'publish_operation_id' => $publishId,
            'repository' => $repository->owner.'/'.$repository->name, 'default_branch' => $project->default_branch,
            'smoke_workflow' => $workflow, 'pr_number' => $number, 'head_sha' => $head, 'token' => ['$secret' => 'github.dependency_merge', 'id' => $project->id]];
    }

    /** @phpstan-assert true $condition */
    private function check(bool $condition, string $code): void
    {
        if (! $condition) {
            throw new ResourceOperationException($code, 'Dependency merge requires an enabled Project and its owned batch publication.', 409);
        }
    }
}

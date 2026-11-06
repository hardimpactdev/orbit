<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Actions\Tasks\CreateTaskGroupAction;
use App\Actions\Tasks\StartTaskProvisionAction;
use App\Data\Tasks\CreateTaskGroupData;
use App\Data\Tasks\TaskInputData;
use App\Domain\GitHub\GitHubAccessToken;
use App\Domain\GitHub\GitHubAppStore;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GitHubTokenGrant;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCancelIntent;
use App\Domain\Tasks\TaskExecutionHold;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Infrastructure\GitHub\HttpGitHubApi;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Operation;
use App\Models\Project;
use App\Models\ProjectInspection;
use App\Models\Task;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Supplies execution copies and records native requests through normal Gateway admission. */
final readonly class ProjectMaintenanceProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        if ($operation->kind === 'project.detect') {
            // The run gets the last inspection, so it reads again only what changed; the Operation keeps none of it.
            $inspection = ProjectInspection::query()->where('project_id', $operation->target_id)->first();
            $checked = $inspection?->registry_checked_at;

            $repository = GitHubRepository::fromOrigin(ValidatedData::string(ValidatedData::object($operation->input['project'])['repository'] ?? null))
                ?? throw new ResourceOperationException('project.maintenance_unavailable', 'The admitted repository is unavailable.', 422);

            return ['intent' => ['project' => $operation->input['project'], 'repository' => $repository->owner.'/'.$repository->name,
                'previous' => ['manifests' => (object) ($inspection->manifests ?? []), 'sources' => $inspection->sources ?? []],
                'check_due' => ($operation->input['open'] ?? false) === true || $checked === null || $checked->addDay()->isPast()],
                // The copy is the whole request header, so curl reads it from stdin and no command line names a token.
                'execution_secrets' => ['github_authorization' => app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', 'project-dependency-read-'.$operation->id,
                    'Authorization: Bearer '.$this->token($operation, ['contents' => 'read'])->reveal())]];
        }
        $copies = [];
        if ($operation->kind === 'project.observe' && ($operation->input['pr_urls'] ?? []) !== []) {
            $copies['github_token'] = app(SwampCommandStep::class)->executionCopy('orbit-control-inputs', 'project-dependency-read-'.$operation->id,
                $this->token($operation, ['contents' => 'read', 'pull_requests' => 'read'])->reveal());
        }

        return ['intent' => $operation->input, 'execution_secrets' => $copies];
    }

    /** @param  array<string, string>  $permissions */
    private function token(Operation $operation, array $permissions): GitHubAccessToken
    {
        $project = ValidatedData::object($operation->input['project']);
        $repository = GitHubRepository::fromOrigin(ValidatedData::string($project['repository']));
        if ($repository === null) {
            throw new ResourceOperationException('project.maintenance_unavailable', 'The admitted repository is unavailable.', 422);
        }
        $credentials = app(GitHubAppStore::class)->credentials();
        if ($credentials === null) {
            throw new ResourceOperationException('operation.github_token_unavailable', 'Repository installation unavailable.', 503);
        }

        return app(HttpGitHubApi::class)->scopedRepositoryToken($credentials, GitHubTokenGrant::from(['repository' => $repository->owner.'/'.$repository->name, 'permissions' => $permissions]));
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->whereKey($operation->id)->lockForUpdate()->firstOrFail();
            if ($operation->status->terminal()) {
                return $operation;
            }
            $result = ValidatedData::object($data['result'] ?? []);
            if ($operation->kind === 'project.detect') {
                $result = $data['status'] === 'succeeded' ? $this->inspected($operation, $result) : ['error_code' => $data['error_code'] ?? 'project.scan_failed'];

                return app(DirectOperation::class)->project($operation, $data, $this->admit($operation, $result, $data['status'] === 'succeeded'));
            }
            if (in_array($operation->kind, ['project.observe', 'project.routine'], true)) {
                $result = ValidatedData::object($result['maintenance'] ?? []);
            }
            if ($data['status'] === 'succeeded' && ($result['project_id'] ?? null) !== $operation->target_id) {
                throw new ResourceOperationException('operation.result_invalid', 'Project result identity differs from the admitted input.', 422);
            }
            if ($operation->kind === 'project.observe' && $data['status'] === 'succeeded') {
                $this->observe($operation, $result);
            }
            $result = $this->admit($operation, $result, $data['status'] === 'succeeded');

            return app(DirectOperation::class)->project($operation, $data, $result);
        });
    }

    /**
     * Stores what the inspection read and found. A source, registry check or update step that did not run
     * leaves its stored part as it was. The Operation keeps a summary; only `--open` or the Schedule asks for a group.
     *
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function inspected(Operation $operation, array $result): array
    {
        $read = ValidatedData::object($result['manifests'] ?? null);
        $commit = ValidatedData::string($read['commit'] ?? null);
        $inspection = ProjectInspection::query()->firstOrNew(['project_id' => $operation->target_id]);
        $inspection->fill(['source_commit' => $commit, 'manifests' => ValidatedData::object($read['manifests'] ?? null), 'inspected_at' => now()]);
        if (array_key_exists('sources', $result)) {
            $inspection->sources = array_values(Arr::array($result, 'sources'));
        }
        if (array_key_exists('registries', $result)) {
            $inspection->registry_checked_at = CarbonImmutable::parse(ValidatedData::string($result['registries']));
        }
        $updates = array_key_exists('updates', $result) ? ValidatedData::object($result['updates']) : null;
        if ($updates !== null) {
            $inspection->fill(['targets' => array_values(Arr::array($updates, 'targets')), 'blocked' => array_values(Arr::array($updates, 'blocked'))]);
        }
        $inspection->sources ??= [];
        $inspection->targets ??= [];
        $inspection->blocked ??= [];
        $inspection->save();

        return ['project_id' => $operation->target_id, 'source_commit' => $commit, 'changed_sources' => count(Arr::array($read, 'changed', [])),
            'targets' => count($inspection->targets), 'blocked' => count($inspection->blocked),
            'requests' => ($operation->input['open'] ?? false) === true && $updates !== null ? Arr::array($updates, 'requests', []) : []];
    }

    /** @param array<string,mixed> $result */
    private function observe(Operation $operation, array $result): void
    {
        $admitted = ValidatedData::object($operation->input['project']);
        $project = Project::query()->find($operation->target_id);
        if ($project === null || ! $project->maintenance || $project->repository_url !== ($admitted['repository'] ?? null)
            || $project->default_branch !== ($admitted['default_branch'] ?? null) || $project->task_check !== ($admitted['check'] ?? null)) {
            return;
        }
        $repository = GitHubRepository::fromOrigin(ValidatedData::string($admitted['repository']));
        foreach (Arr::array($result, 'resume_group_ids', []) as $groupId) {
            $group = Task::topLevel()->where('project_id', $operation->target_id)->whereKey(ValidatedData::integer($groupId))->lockForUpdate()->first();
            $captured = collect(Arr::array($operation->input, 'groups', []))->firstWhere('id', $groupId);
            if ($group !== null && is_array($captured) && ($captured['maintenance'] ?? null) === $group->maintenance
                && $group->status === TaskGroupStatus::Todo && $group->taskable_id === null
                && ($group->maintenance['admission_pending'] ?? false) === true && ! $group->assistance_requested
                && ! TaskExecutionHold::active($group)) {
                DB::afterCommit(fn () => $this->startProvision($group));
            }
        }
        foreach (array_map(ValidatedData::object(...), Arr::array($result, 'observations', [])) as $observation) {
            $group = Task::topLevel()->where('project_id', $operation->target_id)->whereKey(ValidatedData::integer($observation['group_id']))->lockForUpdate()->first();
            if ($group === null || $group->watched_pr_url !== ($observation['url'] ?? null)) {
                continue;
            }
            $captured = collect(Arr::array($operation->input, 'groups', []))->firstWhere('id', $group->id);
            if (! is_array($captured) || ($captured['watched_pr_state'] ?? null) !== $group->watched_pr_state) {
                continue;
            }
            $number = $repository?->pullRequestNumber(ValidatedData::string($observation['url']));
            $state = $observation['state'] ?? null;
            if ($number === null || ! in_array($state, ['open', 'closed', 'merged'], true)) {
                throw new ResourceOperationException('operation.result_invalid', 'The PR observation differs from its admitted Project.', 422);
            }
            $group->update(['watched_pr_number' => $number, 'watched_pr_state' => $state]);
            if ($group->maintenance !== null && in_array($state, ['merged', 'closed'], true)
                && $group->pr_url === $group->watched_pr_url && ! TaskExecutionHold::active($group)
                && $group->tasks()->where('status', '!=', TaskStatus::Completed)->doesntExist()
                && ! $group->assistance_requested) {
                $key = 'maintenance-finish:'.$group->id.':'.ValidatedData::integer($group->maintenance['publish_operation_id']);
                try {
                    app(OperationIdempotency::class)->under('tasks.maintenance_finish', $key, hash('sha256', $key), function () use ($group, $state): void {
                        $group->update(['status' => TaskGroupStatus::Settling, 'watched_pr_completion' => $state]);
                        app(TaskCancelIntent::class)->finishMaintenance($group);
                    });
                } catch (ResourceOperationException $exception) {
                    TaskAssistance::apply($group, AssistanceKind::RemovalFailure, reason: 'Maintenance cleanup admission refused: '.$exception->errorCode);
                }
            }
        }
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function admit(Operation $operation, array $result, bool $succeeded): array
    {
        if (! $succeeded) {
            return $result;
        }
        $project = Project::query()->whereKey($operation->target_id)->lockForUpdate()->firstOrFail();
        $admittedProject = ValidatedData::object($operation->input['project']);
        if (! $project->maintenance || $project->repository_url !== $admittedProject['repository'] || $project->default_branch !== $admittedProject['default_branch']
            || $project->task_check !== $admittedProject['check']) {
            return [...$result, 'admitted_group_ids' => [], 'admission_blocked' => 'project_settings_changed'];
        }
        $admitted = [];
        foreach (array_map(ValidatedData::object(...), Arr::array($result, 'requests', [])) as $request) {
            if (($request['action'] ?? null) !== 'create_group' || ($request['project_id'] ?? null) !== $operation->target_id) {
                throw new ResourceOperationException('operation.result_invalid', 'Unknown Project maintenance admission.', 422);
            }
            $input = ValidatedData::object($request['input']);
            $existing = Task::topLevel()->where('project_id', $operation->target_id)
                ->where('orchestration', 'external')->where('brief', ValidatedData::string($input['brief']))->orderBy('id')->get();
            if ($existing->count() > 1) {
                throw new ResourceOperationException('project.maintenance_ambiguous', 'This schedule has more than one matching group.', 409);
            }
            $group = $existing->first();
            $blockers = Task::topLevel()->where('project_id', $operation->target_id);
            if ($group !== null) {
                $blockers->whereKeyNot($group->id);
            }
            $blocked = $blockers->with('tasks')->get()->contains(static fn (Task $prior): bool => ! self::released($prior));
            if ($blocked) {
                return [...$result, 'admitted_group_ids' => $admitted, 'admission_blocked' => 'project_has_unfinished_work'];
            }
            $group ??= app(CreateTaskGroupAction::class)->execute(new CreateTaskGroupData(
                projectId: $operation->target_id, title: ValidatedData::string($input['title']), brief: ValidatedData::string($input['brief']),
                status: TaskGroupStatus::Backlog,
                tasks: array_values(array_map(TaskInputData::from(...), Arr::array($input, 'tasks', []))),
            ));
            if ($group->maintenance === null && $group->status === TaskGroupStatus::Backlog && $group->taskable_id === null) {
                $maintenance = ValidatedData::object($request['maintenance']);
                if (! in_array($maintenance['mode'] ?? null, ['routine', 'package'], true)
                    || (ValidatedData::object($maintenance['project'] ?? [])['projectId'] ?? null) !== $project->id) {
                    throw new ResourceOperationException('operation.result_invalid', 'The maintenance intent must name its admitted Project.', 422);
                }
                $group->update(['maintenance' => [...$maintenance, 'stage' => 'provision'], 'status' => TaskGroupStatus::Todo]);
                DB::afterCommit(fn () => $this->startProvision($group));
            }
            $admitted[] = $group->id;
            $operation->forceFill(['state' => [...($operation->state ?? []), 'maintenance_group_id' => $group->id]])->save();
        }

        return [...$result, 'admitted_group_ids' => $admitted];
    }

    private function startProvision(Task $group): void
    {
        $key = 'maintenance:'.$group->id.':provision';
        try {
            app(OperationIdempotency::class)->under('tasks.provision', $key, hash('sha256', $key), function () use ($group): void {
                $provision = app(StartTaskProvisionAction::class)->execute($group, 'project-maintenance', null);
                $group->update(['maintenance' => [...($group->maintenance ?? []), 'operation_id' => $provision->id, 'admission_pending' => false]]);
            });
        } catch (ResourceOperationException $exception) {
            if ($exception->errorCode === 'tasks.capacity_unavailable') {
                $group->update(['maintenance' => [...($group->maintenance ?? []), 'admission_pending' => true]]);
            } else {
                TaskAssistance::apply($group, AssistanceKind::Failure, reason: 'Maintenance provision admission refused: '.$exception->errorCode);
            }
        }
    }

    /** Ended work releases maintenance capacity only after its PR and assistance settle. */
    private static function released(Task $group): bool
    {
        $settled = static fn (Task $task): bool => ! $task->assistance_requested && ! $task->assistance_kind
            && ! $task->assistance_reason && ! $task->assistance_question;

        return in_array($group->status, [TaskGroupStatus::Completed, TaskGroupStatus::Cancelled], true)
            && $group->taskable_id === null && $settled($group)
            && ($group->status === TaskGroupStatus::Cancelled || ((! $group->pr_url && ! $group->watched_pr_url) || $group->watched_pr_state === 'merged'))
            && $group->tasks->every(static fn (Task $task): bool => in_array($task->status->value, ['completed', 'failed', 'cancelled'], true) && $settled($task));
    }
}

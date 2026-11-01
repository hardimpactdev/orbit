<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\ScheduledResultProjection;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Infrastructure\Swamp\SwampSchedulePublisher;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use App\Support\ValidatedData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Committed public schedule inputs and terminal admissions. Swamp chooses cadence and work. */
final readonly class ScheduledProjectMaintenance implements ScheduledResultProjection
{
    public const string Workflow = 'a37dfa1b-1633-475f-90c7-dfd9c83008ca';

    public const string StateKey = 'swamp.project-maintenance';

    public function activate(): void
    {
        if (app(TaskExtensionState::class)->enabled()) {
            $this->publish();
        }
    }

    public function invalidate(): void
    {
        DB::transaction(function (): void {
            $record = $this->query()->lockForUpdate()->first();
            if ($record === null) {
                return;
            }
            $state = $this->decode($record);
            $record->value = json_encode([...$state, 'dirty' => true], JSON_THROW_ON_ERROR);
            $record->saveQuietly();
            DB::afterCommit(function (): void {
                try {
                    $this->publish();
                } catch (Throwable $exception) {
                    Log::warning('Native Project schedule input publication was not confirmed.', ['exception_type' => $exception::class]);
                }
            });
        });
    }

    public function publish(): void
    {
        if (! app(TaskExtensionState::class)->enabled()) {
            return;
        }
        // Serialize the committed snapshot and its one bounded native publication together.
        $failure = DB::transaction(function (): ?Throwable {
            $record = $this->state();
            $state = $this->decode($record);
            $storedInputs = ValidatedData::object($state['inputs'] ?? []);
            $snapshot = $this->capture();
            if (($state['snapshot'] ?? null) === $snapshot && ($state['published'] ?? false)
                && ($storedInputs['admissions'] ?? []) === ($state['admissions'] ?? [])) {
                $record->value = json_encode([...$state, 'dirty' => false], JSON_THROW_ON_ERROR);
                $record->saveQuietly();

                return null;
            }
            $revision = ValidatedData::integer($state['revision'] ?? 0) + 1;
            $inputs = ['revision' => $revision, 'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)), ...$snapshot,
                'admissions' => ($state['admissions'] ?? []) === [] ? (object) [] : $state['admissions']];
            $next = [...$state, 'revision' => $revision, 'snapshot' => $snapshot, 'inputs' => $inputs, 'published' => false, 'dirty' => true];
            try {
                app(SwampSchedulePublisher::class)->publish(self::Workflow, '40 * * * * *', $inputs);
            } catch (Throwable $exception) {
                $record->value = json_encode($next, JSON_THROW_ON_ERROR);
                $record->saveQuietly();

                return $exception;
            }
            $record->value = json_encode([...$next, 'published' => true, 'dirty' => false], JSON_THROW_ON_ERROR);
            $record->saveQuietly();

            return null;
        });
        if ($failure !== null) {
            throw $failure;
        }
    }

    public function applyScheduled(string $workflowId, array $run, array $result): void
    {
        if ($workflowId !== self::Workflow) {
            throw new \LogicException('Unknown Project maintenance schedule.');
        }
        DB::transaction(function () use ($run, $result): void {
            $record = $this->state();
            $state = $this->decode($record);
            $previous = ValidatedData::object($state['terminal'] ?? []);
            if (($previous['run_id'] ?? null) === $run['id'] || (is_string($previous['started_at'] ?? null)
                && Carbon::parse($previous['started_at'])->greaterThanOrEqualTo(Carbon::parse(Arr::string($run, 'startedAt'))))) {
                return;
            }
            $inputs = ValidatedData::object($run['inputs'] ?? []);
            $storedInputs = ValidatedData::object($state['inputs'] ?? []);
            $usable = app(TaskExtensionState::class)->enabled() && ($run['status'] ?? null) === 'succeeded'
                && ($state['published'] ?? false) && ! ($state['dirty'] ?? true)
                && $inputs === ($state['inputs'] ?? null)
                && ($inputs['revision'] ?? null) === ($state['revision'] ?? null)
                && ($result['revision'] ?? null) === ($inputs['revision'] ?? null)
                && ($result['snapshot_hash'] ?? null) === ($inputs['snapshot_hash'] ?? null)
                && ($inputs['snapshot_hash'] ?? null) === ($storedInputs['snapshot_hash'] ?? null)
                && ($state['snapshot'] ?? null) === $this->capture();
            $admissions = ValidatedData::object($state['admissions'] ?? []);
            if ($usable) {
                foreach (array_map(ValidatedData::object(...), Arr::array($result, 'requests', [])) as $request) {
                    $projectId = ValidatedData::integer($request['project_id'] ?? null);
                    $mode = ValidatedData::string($request['mode'] ?? null);
                    $key = ValidatedData::string($request['key'] ?? null);
                    if (! in_array($mode, ['detect', 'routine', 'observe'], true) || ! preg_match('/\A[1-9][0-9]*-(detect|routine|observe)-[0-9]{4}-[0-9]{2}-[0-9]{2}(?:T[0-9]{2}:[0-9]{2})?\z/D', $key)
                        || ! str_starts_with($key, $projectId.'-'.$mode.'-')) {
                        throw new ResourceOperationException('operation.result_invalid', 'Invalid native Project maintenance request.', 422);
                    }
                    $project = Project::query()->lockForUpdate()->find($projectId);
                    $snapshot = collect(Arr::array($state['snapshot'], 'projects'))->firstWhere('id', $projectId);
                    if ($project === null || ! is_array($snapshot) || (ValidatedData::object($admissions[$projectId.':'.$mode] ?? [])['key'] ?? null) === $key) {
                        continue;
                    }
                    if (Operation::query()->where('target_type', 'project')->where('target_id', $projectId)
                        ->whereIn('kind', ['project.detect', 'project.routine', 'project.observe'])->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
                        continue;
                    }
                    $identity = app(OperationIdempotency::class);
                    $prior = [$identity->kind, $identity->key, $identity->fingerprint];
                    $identity->kind = 'project.'.$mode;
                    $identity->key = 'native-maintenance:'.$key;
                    $identity->fingerprint = hash('sha256', json_encode([$projectId, $mode, $key, $snapshot], JSON_THROW_ON_ERROR));
                    try {
                        $operation = app(ProjectMaintenanceIntent::class)->start($project, $mode);
                    } finally {
                        [$identity->kind, $identity->key, $identity->fingerprint] = $prior;
                    }
                    $admissions[$projectId.':'.$mode] = ['key' => $key, 'operation_id' => $operation->id];
                }
            }
            $record->value = json_encode([...$state, 'admissions' => $admissions,
                'terminal' => ['run_id' => $run['id'], 'started_at' => $run['startedAt'], 'status' => $usable ? 'recorded' : 'stale'], 'dirty' => true], JSON_THROW_ON_ERROR);
            $record->saveQuietly();
            DB::afterCommit(fn () => $this->notify());
        });
    }

    private function notify(): void
    {
        try {
            $this->publish();
        } catch (Throwable $exception) {
            Log::warning('Native Project schedule input publication was not confirmed.', ['exception_type' => $exception::class]);
        }
    }

    /** @return array<string,mixed> */
    private function capture(): array
    {
        return ['projects' => Project::query()->where('source_access', ProjectSourceAccess::GitHubApp)->whereNotNull('task_check')
            ->where('task_check', '!=', '')->whereNotNull('default_branch')->orderBy('id')
            ->get(['id', 'repository_url', 'default_branch', 'task_check'])->map(static fn (Project $project): array => [
                'id' => $project->id, 'repository' => $project->repository_url, 'default_branch' => $project->default_branch, 'check' => $project->task_check,
            ])->all(),
            'groups' => Task::topLevel()->orderBy('id')->get(['id', 'project_id', 'status', 'taskable_id', 'brief', 'pr_url', 'watched_pr_url', 'watched_pr_state',
                'assistance_requested', 'assistance_kind', 'assistance_question', 'assistance_reason', 'maintenance'])->toArray(),
            'operations' => Operation::query()->where('target_type', 'project')->whereIn('kind', ['project.detect', 'project.routine', 'project.observe'])
                ->whereNotIn('status', OperationStatus::terminalValues())->orderBy('id')->get(['id', 'target_id', 'kind', 'status'])->toArray()];
    }

    /** @return Builder<Setting> */
    private function query(): Builder
    {
        return Setting::query()->where('scope_type', SettingScopeType::Gateway)->where('scope_id', 0)->where('key', self::StateKey);
    }

    private function state(): Setting
    {
        return Setting::withoutEvents(fn (): Setting => $this->query()->lockForUpdate()->firstOrCreate([
            'scope_type' => SettingScopeType::Gateway, 'scope_id' => 0, 'key' => self::StateKey,
        ], ['value' => '{}', 'is_secret' => false]));
    }

    /** @return array<string,mixed> */
    private function decode(Setting $state): array
    {
        return ValidatedData::object(json_decode($state->value ?? '{}', true, 512, JSON_THROW_ON_ERROR));
    }
}

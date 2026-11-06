<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\ScheduledResultProjection;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
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
use LogicException;
use Throwable;

/**
 * The hourly Project maintenance tick. The Swamp schedule only reports that it fired, with the Projects that the Swamp source configures
 * and the request keys of the fire; its trigger carries no Project or task group data. The Gateway selects the work from its own tables
 * when it projects the fire, and admits it through the normal guarded start. See docs/cli/project/observe.mmd.
 */
final readonly class ScheduledProjectMaintenance implements ScheduledResultProjection
{
    public const string Workflow = 'a37dfa1b-1633-475f-90c7-dfd9c83008ca';

    public const string StateKey = 'swamp.project-maintenance';

    /** Hourly at :17:40: each opted-in Project is inspected every hour, and routines are weekly. */
    public const string Schedule = '40 17 * * * *';

    /** In the order of preference: a due routine, then an observation while a group waits, else the hourly inspection. */
    private const array Modes = ['routine', 'observe', 'detect'];

    private const array Kinds = ['project.detect', 'project.routine', 'project.observe'];

    /** The trigger is fixed, so the publisher sends it once; a definition that serve holds is not sent again. */
    public function activate(): void
    {
        if (! app(TaskExtensionState::class)->enabled()) {
            return;
        }
        try {
            app(SwampSchedulePublisher::class)->publish(self::Workflow, self::Schedule, []);
        } catch (Throwable $exception) {
            Log::warning('The Project maintenance schedule was not confirmed.', ['error' => $exception instanceof ResourceOperationException ? $exception->errorCode : $exception::class]);
        }
    }

    public function applyScheduled(string $workflowId, array $run, array $result): void
    {
        if ($workflowId !== self::Workflow) {
            throw new LogicException('Unknown Project maintenance schedule.');
        }
        $tick = ValidatedData::object(json_decode(Arr::string($result, 'tick_json', '{}'), true, flags: JSON_THROW_ON_ERROR));
        DB::transaction(function () use ($run, $tick): void {
            $record = $this->state();
            $state = $this->decode($record);
            $previous = ValidatedData::object($state['terminal'] ?? []);
            if (($previous['run_id'] ?? null) === $run['id'] || (is_string($previous['started_at'] ?? null)
                && Carbon::parse($previous['started_at'])->greaterThanOrEqualTo(Carbon::parse(Arr::string($run, 'startedAt'))))) {
                return;
            }
            $admissions = ValidatedData::object($state['admissions'] ?? []);
            $usable = app(TaskExtensionState::class)->enabled() && ($run['status'] ?? null) === 'succeeded';
            if ($usable) {
                foreach ($this->due($tick, $admissions) as [$project, $mode, $key]) {
                    if (Operation::query()->where('target_type', 'project')->where('target_id', $project->id)
                        ->whereIn('kind', self::Kinds)->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
                        continue;
                    }
                    $operation = app(OperationIdempotency::class)->under('project.'.$mode, 'native-maintenance:'.$key,
                        hash('sha256', json_encode([$project->id, $mode, $key, $this->identity($project)], JSON_THROW_ON_ERROR)),
                        fn () => app(ProjectMaintenanceIntent::class)->start($project, $mode, open: true));
                    $admissions[$project->id.':'.$mode] = ['key' => $key, 'operation_id' => $operation->id];
                }
            }
            $record->value = json_encode(['admissions' => $admissions,
                'terminal' => ['run_id' => $run['id'], 'started_at' => $run['startedAt'], 'status' => $usable ? 'recorded' : 'skipped']], JSON_THROW_ON_ERROR);
            $record->saveQuietly();
        });
    }

    /**
     * One request for each opted-in Project that the tick lists with the same repository, default branch and check: not while one of
     * its maintenance tasks is open, and not for a key that was admitted before.
     *
     * @param  array<string,mixed>  $tick
     * @param  array<string,mixed>  $admissions
     * @return list<array{Project, string, string}>
     */
    private function due(array $tick, array $admissions): array
    {
        $configured = [];
        foreach (Arr::array($tick, 'projects', []) as $entry) {
            $entry = ValidatedData::object($entry);
            $configured[ValidatedData::integer($entry['id'])] = $entry;
        }
        $busy = Operation::query()->where('target_type', 'project')->whereIn('kind', self::Kinds)
            ->whereNotIn('status', OperationStatus::terminalValues())->pluck('target_id')->all();
        // A group that has not ended and holds a pull request, or waits for capacity, makes an observation due.
        $waiting = Task::topLevel()->whereNotIn('status', array_map(static fn (TaskGroupStatus $status): string => $status->value, TaskGroupStatus::endedStatuses()))
            ->get(['project_id', 'pr_url', 'watched_pr_url', 'maintenance'])
            ->filter(static fn (Task $group): bool => (bool) ($group->pr_url ?: $group->watched_pr_url ?: ($group->maintenance['admission_pending'] ?? false)))
            ->pluck('project_id')->unique()->all();
        $due = [];
        foreach ($this->projects() as $project) {
            $entry = $configured[$project->id] ?? null;
            if ($entry === null || ! $this->matches($project, $entry) || in_array($project->id, $busy, true)) {
                continue;
            }
            $keys = ValidatedData::object($entry['keys'] ?? []);
            foreach (self::Modes as $mode) {
                if ($mode === 'observe' && ! in_array($project->id, $waiting, true)) {
                    continue;
                }
                $key = ValidatedData::string($keys[$mode] ?? null);
                if (preg_match('/\A[1-9][0-9]*-(detect|routine|observe)-[0-9]{4}-[0-9]{2}-[0-9]{2}(?:T[0-9]{2}:[0-9]{2})?\z/D', $key) !== 1
                    || ! str_starts_with($key, $project->id.'-'.$mode.'-')) {
                    throw new ResourceOperationException('operation.result_invalid', 'Invalid native Project maintenance request.', 422);
                }
                if ((ValidatedData::object($admissions[$project->id.':'.$mode] ?? [])['key'] ?? null) === $key) {
                    continue;
                }
                $due[] = [$project, $mode, $key];
                break;
            }
        }

        return $due;
    }

    /** @return iterable<Project> the opted-in Projects, locked for the rest of the transaction so a settings change cannot slip in after the match */
    private function projects(): iterable
    {
        return Project::query()->lockForUpdate()->where('maintenance', true)->where('source_access', ProjectSourceAccess::GitHubApp)->whereNotNull('task_check')
            ->where('task_check', '!=', '')->whereNotNull('default_branch')->orderBy('id')->get();
    }

    /**
     * The Swamp source configures the Project, and its settings are the ones the Gateway holds. The repository is compared as Swamp does:
     * `git@github.com:` or `https://github.com/`, without `.git`, in lower case.
     *
     * @param  array<string,mixed>  $entry
     */
    private function matches(Project $project, array $entry): bool
    {
        $identity = preg_match('#\A(?:git@github\.com:|https://github\.com/)([^?\#]+)\z#', (string) $project->repository_url, $match) === 1
            ? strtolower('github.com/'.preg_replace('/\.git\z/', '', $match[1])) : null;

        return $identity !== null && $identity === ($entry['repository'] ?? null)
            && $project->default_branch === ($entry['default_branch'] ?? null) && $project->task_check === ($entry['check'] ?? null);
    }

    /** @return array<string,mixed> */
    private function identity(Project $project): array
    {
        return ['id' => $project->id, 'repository' => $project->repository_url, 'default_branch' => $project->default_branch, 'check' => $project->task_check];
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

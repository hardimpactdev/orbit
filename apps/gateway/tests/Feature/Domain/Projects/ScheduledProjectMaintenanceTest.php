<?php

declare(strict_types=1);

use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\ProjectMaintenanceIntent;
use App\Domain\Projects\ScheduledProjectMaintenance;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Infrastructure\Swamp\SwampSchedulePublisher;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Setting;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;

uses(LazilyRefreshDatabase::class);

beforeEach(fn () => app(TaskExtensionState::class)->enable());

/** An opted-in Project that reads its repository through the GitHub App and has a default branch and a task check. */
function maintainedProject(array $attributes = []): Project
{
    return Project::query()->create([...['name' => 'launch', 'slug' => 'launch-'.Str::random(6), 'repository_url' => 'https://github.com/nckrtl/launch-'.Str::lower(Str::random(6)).'.git', 'default_branch' => 'main',
        'root' => '.', 'source_access' => 'github_app', 'task_check' => 'composer check', 'maintenance' => true], ...$attributes]);
}

/** The entry that the tick lists for a Project that the Swamp source configures, with the keys of the fire of Wednesday 10:30 UTC, the examples that inputs_test.ts pins from the Swamp side. */
function tickEntry(Project $project, array $overrides = []): array
{
    return [...['id' => $project->id, 'repository' => strtolower((string) preg_replace('#\A(?:git@github\.com:|https://github\.com/)(.+?)(?:\.git)?\z#', 'github.com/$1', (string) $project->repository_url)), 'default_branch' => 'main', 'check' => 'composer check',
        'keys' => ['routine' => $project->id.'-routine-2026-11-02', 'observe' => $project->id.'-observe-2026-11-04T10:30', 'detect' => $project->id.'-detect-2026-11-04T10:00']], ...$overrides];
}

/** @param list<array<string,mixed>> $entries */
function fireTick(array $entries, string $status = 'succeeded', ?string $run = null, string $startedAt = '2026-11-04T10:30:00Z'): void
{
    app(ScheduledProjectMaintenance::class)->applyScheduled(ScheduledProjectMaintenance::Workflow,
        ['id' => $run ?? (string) Str::uuid(), 'startedAt' => $startedAt, 'status' => $status],
        ['tick_json' => json_encode(['projects' => $entries, 'skipped' => []], JSON_THROW_ON_ERROR)]);
}

/** A start that records what it was asked for and leaves a finished task behind, as the real one leaves a task that ends. */
function recordedStarts(OperationStatus $status = OperationStatus::Succeeded): object
{
    $starts = new class($status)
    {
        /** @var list<array{int, string}> */
        public array $calls = [];

        /** @var list<bool> */
        public array $opens = [];

        public function __construct(private readonly OperationStatus $status) {}

        public function start(Project $project, string $mode, ?string $requestId = null, bool $open = false): Operation
        {
            $this->calls[] = [$project->id, $mode];
            $this->opens[] = $open;

            return Operation::query()->create(['kind' => 'project.'.$mode, 'target_type' => 'project', 'target_id' => $project->id, 'node_id' => null,
                'input' => [], 'status' => $this->status, 'sequence' => 1, 'attempts' => 1]);
        }
    };
    app()->instance(ProjectMaintenanceIntent::class, $starts);

    return $starts;
}

function maintenanceState(): array
{
    $row = Setting::query()->where('scope_type', SettingScopeType::Gateway)->where('scope_id', 0)->where('key', ScheduledProjectMaintenance::StateKey)->first();

    return json_decode($row?->value ?? '{}', true);
}

it('admits the weekly routine first, and the hourly inspection once the routine of the week was admitted', function (): void {
    $starts = recordedStarts();
    $project = maintainedProject();

    fireTick([tickEntry($project)], startedAt: '2026-11-04T10:30:00Z');
    fireTick([tickEntry($project)], startedAt: '2026-11-04T11:30:00Z');

    expect($starts->calls)->toBe([[$project->id, 'routine'], [$project->id, 'detect']])
        ->and($starts->opens)->toBe([true, true])
        ->and(maintenanceState()['admissions'])->toMatchArray([$project->id.':routine' => ['key' => $project->id.'-routine-2026-11-02', 'operation_id' => Operation::query()->where('kind', 'project.routine')->value('id')],
            $project->id.':detect' => ['key' => $project->id.'-detect-2026-11-04T10:00', 'operation_id' => Operation::query()->where('kind', 'project.detect')->value('id')]]);
});

it('admits an observation, not an inspection, while a group that has not ended holds a pull request or waits for capacity', function (array $group): void {
    $starts = recordedStarts();
    $project = maintainedProject();
    Task::query()->create(['project_id' => $project->id, 'title' => 'Maintenance', 'brief' => 'Update', ...$group]);
    Setting::withoutEvents(fn () => Setting::query()->create(['scope_type' => SettingScopeType::Gateway, 'scope_id' => 0, 'key' => ScheduledProjectMaintenance::StateKey, 'is_secret' => false,
        'value' => json_encode(['admissions' => [$project->id.':routine' => ['key' => $project->id.'-routine-2026-11-02', 'operation_id' => 1]]])]));

    fireTick([tickEntry($project)]);

    expect($starts->calls)->toBe([[$project->id, 'observe']]);
})->with([
    'a pull request' => [['status' => TaskGroupStatus::WaitingForReview, 'pr_url' => 'https://github.com/nckrtl/launch/pull/17']],
    'a watched pull request' => [['status' => TaskGroupStatus::Running, 'watched_pr_url' => 'https://github.com/nckrtl/launch/pull/17']],
    'a wait for capacity' => [['status' => TaskGroupStatus::Todo, 'maintenance' => ['admission_pending' => true]]],
]);

it('inspects a Project whose only pull request belongs to a group that ended', function (): void {
    $starts = recordedStarts();
    $project = maintainedProject();
    Task::query()->create(['project_id' => $project->id, 'title' => 'Old', 'brief' => 'Done', 'status' => TaskGroupStatus::Completed, 'pr_url' => 'https://github.com/nckrtl/launch/pull/3']);
    Setting::withoutEvents(fn () => Setting::query()->create(['scope_type' => SettingScopeType::Gateway, 'scope_id' => 0, 'key' => ScheduledProjectMaintenance::StateKey, 'is_secret' => false,
        'value' => json_encode(['admissions' => [$project->id.':routine' => ['key' => $project->id.'-routine-2026-11-02', 'operation_id' => 1]]])]));

    fireTick([tickEntry($project)]);

    expect($starts->calls)->toBe([[$project->id, 'detect']]);
});

it('skips a Project while one of its maintenance tasks is open', function (): void {
    $starts = recordedStarts();
    $project = maintainedProject();
    Operation::query()->create(['kind' => 'project.detect', 'target_type' => 'project', 'target_id' => $project->id, 'node_id' => null, 'input' => [],
        'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 1]);

    fireTick([tickEntry($project)]);

    expect($starts->calls)->toBe([]);
});

it('skips a Project that the tick does not list, or lists with another repository, branch or check, and a Project that is not opted in', function (): void {
    $starts = recordedStarts();
    $unlisted = maintainedProject();
    $moved = maintainedProject();
    $branch = maintainedProject();
    $check = maintainedProject();
    $off = maintainedProject(['maintenance' => false]);
    $noCheck = maintainedProject(['task_check' => '']);
    $cli = maintainedProject(['source_access' => 'gh_cli']);
    $ssh = maintainedProject(['repository_url' => 'git@github.com:nckrtl/launch-ssh.git']);
    $url = maintainedProject(['repository_url' => 'ssh://git@github.com/nckrtl/launch-url.git']);

    fireTick([tickEntry($moved, ['repository' => 'github.com/nckrtl/other']), tickEntry($branch, ['default_branch' => 'trunk']), tickEntry($check, ['check' => 'composer test']),
        tickEntry($off), tickEntry($noCheck), tickEntry($cli), tickEntry($ssh), tickEntry($url, ['repository' => 'github.com/nckrtl/launch-url'])]);

    expect($starts->calls)->toBe([[$ssh->id, 'routine']]);
});

it('admits nothing for a run that failed or while the Tasks extension is off, and still records the run', function (string $status, bool $enabled): void {
    $starts = recordedStarts();
    $project = maintainedProject();
    $enabled || app(TaskExtensionState::class)->disable();

    fireTick([tickEntry($project)], status: $status, run: 'run-1');

    expect($starts->calls)->toBe([])
        ->and(maintenanceState()['terminal'])->toMatchArray(['run_id' => 'run-1', 'status' => 'skipped']);
})->with([
    'a failed run' => ['failed', true],
    'the extension off' => ['succeeded', false],
]);

it('projects a fire once: a run it recorded, or an older one, admits nothing again', function (): void {
    $starts = recordedStarts();
    $project = maintainedProject();

    fireTick([tickEntry($project)], run: 'run-1', startedAt: '2026-11-04T10:30:00Z');
    fireTick([tickEntry($project)], run: 'run-1', startedAt: '2026-11-04T10:30:00Z');
    fireTick([tickEntry($project)], run: 'run-0', startedAt: '2026-11-04T09:30:00Z');

    expect($starts->calls)->toBe([[$project->id, 'routine']]);
});

it('refuses a request key that does not belong to its Project and mode', function (string $mode, string $key): void {
    recordedStarts();
    $project = maintainedProject();
    $keys = tickEntry($project)['keys'];
    $keys[$mode] = $key === 'own' ? '999-'.$mode.'-2026-11-02' : $key;

    expect(fn () => fireTick([tickEntry($project, ['keys' => $keys])]))->toThrow(ResourceOperationException::class, 'Invalid native Project maintenance request');
})->with([
    'another Project' => ['routine', 'own'],
    'another mode' => ['routine', '1-detect-2026-11-02'],
    'free text' => ['routine', '1-routine-tomorrow'],
]);

it('publishes its fixed trigger with no inputs, and a failed publication does not stop the reader', function (): void {
    $publisher = new class
    {
        /** @var list<array{string, string, array<string,mixed>}> */
        public array $calls = [];

        public ?Throwable $fail = null;

        public function publish(string $workflow, string $schedule, array $inputs): void
        {
            $this->calls[] = [$workflow, $schedule, $inputs];
            if ($this->fail !== null) {
                throw $this->fail;
            }
        }
    };
    app()->instance(SwampSchedulePublisher::class, $publisher);

    app(ScheduledProjectMaintenance::class)->activate();
    $publisher->fail = new ResourceOperationException('swamp.schedule_publish_unavailable', 'x', 503);
    app(ScheduledProjectMaintenance::class)->activate();
    app(TaskExtensionState::class)->disable();
    app(ScheduledProjectMaintenance::class)->activate();

    expect($publisher->calls)->toBe([[ScheduledProjectMaintenance::Workflow, '40 17 * * * *', []], [ScheduledProjectMaintenance::Workflow, '40 17 * * * *', []]]);
});

it('drops the retired snapshot from the stored state and keeps the admissions', function (): void {
    Setting::withoutEvents(fn () => Setting::query()->create(['scope_type' => SettingScopeType::Gateway, 'scope_id' => 0, 'key' => ScheduledProjectMaintenance::StateKey, 'is_secret' => false,
        'value' => json_encode(['revision' => 940, 'snapshot' => ['groups' => range(1, 500)], 'inputs' => ['revision' => 940], 'published' => true, 'dirty' => false, 'schedule' => '40 17 * * * *',
            'admissions' => ['32:detect' => ['key' => '32-detect-2026-11-04T10:00', 'operation_id' => 7]], 'terminal' => ['run_id' => 'r', 'started_at' => '2026-11-04T10:30:00Z', 'status' => 'recorded']])]));
    $migration = require database_path('migrations/2027_01_23_000000_drop_the_project_maintenance_snapshot.php');

    $migration->up();
    $migration->up();

    expect(maintenanceState())->toBe(['admissions' => ['32:detect' => ['key' => '32-detect-2026-11-04T10:00', 'operation_id' => 7]],
        'terminal' => ['run_id' => 'r', 'started_at' => '2026-11-04T10:30:00Z', 'status' => 'recorded']]);
});

it('admits nothing for a result in the retired format, and still records the run', function (): void {
    $starts = recordedStarts();
    $project = maintainedProject();

    app(ScheduledProjectMaintenance::class)->applyScheduled(ScheduledProjectMaintenance::Workflow,
        ['id' => 'run-old', 'startedAt' => '2026-11-04T10:30:00Z', 'status' => 'succeeded'],
        ['selection_json' => json_encode(['revision' => 940, 'requests' => [['project_id' => $project->id, 'mode' => 'detect', 'key' => $project->id.'-detect-2026-11-04T10:00']]], JSON_THROW_ON_ERROR)]);

    expect($starts->calls)->toBe([])
        ->and(maintenanceState()['terminal'])->toMatchArray(['run_id' => 'run-old', 'status' => 'recorded']);
});

it('matches the repository as Swamp does: exactly the two supported forms, without .git, in lower case', function (string $url, string $identity, bool $admitted): void {
    $starts = recordedStarts();
    $project = maintainedProject(['repository_url' => $url]);

    fireTick([tickEntry($project, ['repository' => $identity])]);

    expect($starts->calls)->toBe($admitted ? [[$project->id, 'routine']] : []);
})->with([
    'upper case and .git' => ['https://github.com/NckRtl/Case-One.git', 'github.com/nckrtl/case-one', true],
    'the ssh form' => ['git@github.com:nckrtl/ssh-two.git', 'github.com/nckrtl/ssh-two', true],
    'a trailing slash' => ['https://github.com/nckrtl/slash-three/', 'github.com/nckrtl/slash-three', false],
    'ssh://' => ['ssh://git@github.com/nckrtl/url-six.git', 'github.com/nckrtl/url-six', false],
]);

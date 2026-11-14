<?php

declare(strict_types=1);

use App\Actions\Tasks\RunTaskPublishWhenDoneAction;
use App\Actions\Tasks\StartTaskPublishAction;
use App\Data\Tasks\StartTaskPublishData;
use App\Data\Tasks\TaskPublicationData;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskDonePublication;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPrMode;
use App\Domain\Tasks\TaskPublishIntent;
use App\Http\Requests\Tasks\StartTaskPublishRequest;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Task;
use App\Support\ValidatedData;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\Support\SwampNodeFixture;
use Tests\Support\TaskCopyDoneFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    SwampNodeFixture::ensure();
    Queue::fake([StartAdmittedSwampRun::class]);
    app(TaskExtensionState::class)->enable();
});

/** @return list<array<string, mixed>> */
function whenDoneTick(): array
{
    return app(RunTaskPublishWhenDoneAction::class)->execute();
}

function whenDonePublishes(Task $group): int
{
    return Operation::query()->where('kind', OperationKinds::TasksPublish)->where('target_id', $group->id)->count();
}

/** Ends the group's open publish as its run would: failed with this code, and its group lock released. */
function whenDoneEndPublish(Task $group, string $code = TaskDonePublication::LockTimeoutCode): Operation
{
    $operation = Operation::query()->where('kind', OperationKinds::TasksPublish)->where('target_id', $group->id)->whereNotIn('status', OperationStatus::terminalValues())->sole();
    $operation->update(['status' => OperationStatus::Failed, 'error_code' => $code, 'result' => ['error_code' => $code], 'lock_key' => null, 'finished_at' => now()]);

    return $operation;
}

it('publishes the done check of a pr_when_done group by itself, as a ready pull request with Orbit\'s title and body', function (): void {
    $world = TaskCopyDoneFixture::make();
    $world->group->update(['pr_mode' => TaskPrMode::PrWhenDone]);

    $results = whenDoneTick();

    $operation = Operation::query()->where('kind', OperationKinds::TasksPublish)->sole();
    expect($results)->toBe([['group' => $world->group->id, 'check' => $world->check->id, 'result' => 'admitted', 'code' => null, 'operation' => $operation->id]])
        ->and($operation->idempotency_key)->toBe('pr-when-done:'.$world->group->id.':'.$world->check->id)
        ->and($operation->triggered_by)->toBe('gateway:pr-when-done')
        ->and($operation->input['check'])->toBe($world->check->id)
        ->and($operation->input['commit'])->toBe(TaskCopyDoneFixture::Commit)
        ->and(data_get($operation->input, 'pull_request.title'))->toBe('Add the export')
        ->and(data_get($operation->input, 'pull_request.draft'))->toBeFalse()
        ->and(data_get($operation->input, 'pull_request.body'))->toContain('Export the board as CSV.')
        ->and(data_get($operation->input, 'pull_request.body'))->toContain('- Write the export')
        ->and(data_get($operation->input, 'pull_request.body'))->toContain('Checked commit `'.TaskCopyDoneFixture::Commit.'`, handoff check '.$world->check->id.'.')
        ->and(isset($operation->input['workspace']))->toBeTrue()
        ->and(app(OperationIdempotency::class)->key)->toBeNull();
});

it('never starts the publish of the same done check twice, also after its run failed on anything but a lock timeout', function (): void {
    $world = TaskCopyDoneFixture::make();
    $world->group->update(['pr_mode' => TaskPrMode::PrWhenDone]);
    whenDoneTick();
    $operation = Operation::query()->where('kind', OperationKinds::TasksPublish)->sole();
    $operation->update(['status' => OperationStatus::Failed, 'error_code' => 'tasks.push_failed', 'finished_at' => now()]);

    $results = whenDoneTick();

    expect($results)->toBe([['group' => $world->group->id, 'check' => $world->check->id, 'result' => 'skipped', 'code' => 'tasks.push_failed', 'operation' => $operation->id]])
        ->and(whenDonePublishes($world->group))->toBe(1);
});

it('leaves a group waiting while its mode or the mode its done check recorded is review_before_pr', function (?TaskPrMode $group, ?TaskPrMode $project, ?TaskPrMode $recorded): void {
    $world = TaskCopyDoneFixture::make($recorded);
    $world->group->update(['pr_mode' => $group]);
    $world->group->project->update(['task_pr_mode' => $project]);

    expect(whenDoneTick())->toBe([['group' => $world->group->id, 'check' => $world->check->id, 'result' => 'waiting', 'code' => null, 'operation' => null]])
        ->and(whenDonePublishes($world->group))->toBe(0);
})->with([
    'the Gateway default' => [null, null, TaskPrMode::ReviewBeforePr],
    'the group' => [TaskPrMode::ReviewBeforePr, TaskPrMode::PrWhenDone, TaskPrMode::PrWhenDone],
    'the Project changed after the check passed' => [null, TaskPrMode::PrWhenDone, TaskPrMode::ReviewBeforePr],
    'a check from before modes' => [TaskPrMode::PrWhenDone, null, null],
]);

it('ignores a group whose work is not done', function (): void {
    $world = TaskCopyDoneFixture::make();
    $world->group->update(['pr_mode' => TaskPrMode::PrWhenDone]);
    $world->turn->update(['result' => ['state' => 'asking_for_input', 'ended' => true]]);

    expect(whenDoneTick())->toBe([])->and(whenDonePublishes($world->group))->toBe(0);
});

it('keeps a refused admission on the group with its code, keeps the copy, and does not try the same check again', function (): void {
    $world = TaskCopyDoneFixture::make();
    $world->group->update(['pr_mode' => TaskPrMode::PrWhenDone]);
    config(['orbit.tasks.copy_publish_projects' => []]);

    $first = whenDoneTick();
    $second = whenDoneTick();

    $group = $world->group->refresh();
    expect($first[0]['result'])->toBe('skipped')->and($first[0]['code'])->toBe('tasks.copy_publish_held')
        ->and($group->publish_refusal)->toMatchArray(['code' => 'tasks.copy_publish_held', 'check_id' => $world->check->id])
        ->and($second[0])->toMatchArray(['result' => 'skipped', 'code' => 'tasks.copy_publish_held'])
        ->and(whenDonePublishes($group))->toBe(0)
        ->and($group->status)->toBe(TaskGroupStatus::Running)
        ->and($group->taskable_id)->toBe($world->instance->id)
        ->and(Instance::query()->find($world->instance->id)?->task_workspace_owner_id)->toBe($group->id);
});

it('tries again for a new done check and clears the refusal once a publish is admitted', function (): void {
    $world = TaskCopyDoneFixture::make();
    $world->group->update(['pr_mode' => TaskPrMode::PrWhenDone, 'publish_refusal' => ['code' => 'tasks.copy_publish_held', 'check_id' => $world->check->id - 1, 'at' => now()->toIso8601String()]]);

    expect(whenDoneTick()[0]['result'])->toBe('admitted')->and($world->group->refresh()->publish_refusal)->toBeNull();
});

it('registers the tick on the Gateway schedule every minute', function (): void {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toMatch('/\*\s+\*\s+\*\s+\*\s+\*\s+php artisan task:pr-when-done/');
});

it('prints one JSON object per tick and does nothing while the extension is off', function (): void {
    $world = TaskCopyDoneFixture::make();
    $world->group->update(['pr_mode' => TaskPrMode::PrWhenDone]);
    app(TaskExtensionState::class)->disable();

    expect(Artisan::call('task:pr-when-done'))->toBe(0)->and(trim(Artisan::output()))->toBe('')->and(whenDonePublishes($world->group))->toBe(0);

    app(TaskExtensionState::class)->enable();
    Artisan::call('task:pr-when-done');
    expect(data_get(json_decode(Artisan::output(), true), 'groups.0.result'))->toBe('admitted');
});

it('releases a waiting group\'s done check without --check, with the same title and body and a ready pull request', function (): void {
    $world = TaskCopyDoneFixture::make(TaskPrMode::ReviewBeforePr);
    $world->group->update(['publish_refusal' => ['code' => 'tasks.copy_publish_held', 'check_id' => $world->check->id, 'at' => now()->toIso8601String()]]);

    $operation = app(StartTaskPublishAction::class)->execute($world->group->refresh(), new StartTaskPublishData, 'person', null);

    expect($operation->kind)->toBe(OperationKinds::TasksPublish)
        ->and($operation->input['check'])->toBe($world->check->id)
        ->and($operation->input['pull_request'])->toBe(TaskDonePublication::for($world->group, $world->check)->pull_request)
        ->and(data_get($operation->input, 'pull_request.draft'))->toBeFalse()
        ->and($world->group->refresh()->publish_refusal)->toBeNull();
});

it('refuses the release while the work is not done', function (): void {
    $world = TaskCopyDoneFixture::make();
    $world->turn->update(['result' => ['state' => 'asking_for_input', 'ended' => true]]);

    expect(fn () => app(StartTaskPublishAction::class)->execute($world->group->refresh(), new StartTaskPublishData, 'person', null))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('tasks.not_done')->and($exception->status)->toBe(409);
        });
    expect(whenDonePublishes($world->group))->toBe(0);
});

it('replays a repeated release once the group is published', function (): void {
    $world = TaskCopyDoneFixture::make();
    $release = fn (): Operation => app(OperationIdempotency::class)->under('tasks.publish', 'release-1', 'fingerprint-1',
        fn (): Operation => app(StartTaskPublishAction::class)->execute($world->group->refresh(), new StartTaskPublishData, 'person', null));
    $first = $release();
    $world->group->update(['pr_url' => 'https://github.com/example/trial/pull/7']);

    expect($release()->id)->toBe($first->id)->and(whenDonePublishes($world->group))->toBe(1);
});

it('stores a stopped copy as a refusal the group shows, and does not try that check again', function (): void {
    $world = TaskCopyDoneFixture::make();
    $world->group->update(['pr_mode' => TaskPrMode::PrWhenDone]);
    InstanceRuntime::query()->where('instance_id', $world->instance->id)->update(['power_state' => 'stopped']);

    $first = whenDoneTick();
    $second = whenDoneTick();

    $group = $world->group->refresh();
    expect($first[0])->toMatchArray(['result' => 'skipped', 'code' => 'tasks.instance_not_running'])
        ->and($group->publish_refusal)->toMatchArray(['code' => 'tasks.instance_not_running', 'check_id' => $world->check->id])
        ->and(TaskPublicationData::fromModel($group)?->state)->toBe('refused')
        ->and(TaskPublicationData::fromModel($group)?->errorCode)->toBe('tasks.instance_not_running')
        ->and($second[0])->toMatchArray(['result' => 'skipped', 'code' => 'tasks.instance_not_running'])
        ->and(whenDonePublishes($group))->toBe(0);
});

it('shows a cancelled publish of the done check, or one that failed on anything but a lock timeout, as refused, whoever started it, and the tick does not start it again', function (string $caller, OperationStatus $status, ?string $code, string $shown): void {
    $world = TaskCopyDoneFixture::make();
    $world->group->update(['pr_mode' => TaskPrMode::PrWhenDone]);
    $publish = Operation::query()->create(['kind' => OperationKinds::TasksPublish, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $world->group->id, 'node_id' => null,
        'input' => ['check' => $world->check->id, 'commit' => TaskCopyDoneFixture::Commit], 'status' => $status, 'error_code' => $code, 'triggered_by' => $caller,
        'sequence' => 1, 'attempts' => 1, 'finished_at' => now()]);

    $results = whenDoneTick();

    expect($results)->toBe([['group' => $world->group->id, 'check' => $world->check->id, 'result' => 'skipped', 'code' => $shown, 'operation' => $publish->id]])
        ->and(whenDonePublishes($world->group))->toBe(1)
        ->and(TaskPublicationData::fromModel($world->group->refresh())?->state)->toBe('refused')
        ->and(TaskPublicationData::fromModel($world->group)?->errorCode)->toBe($shown);
})->with([
    'a cancelled tick publish' => ['gateway:pr-when-done', OperationStatus::Cancelled, null, 'operation.cancelled'],
    'a failed manual publish' => ['person', OperationStatus::Failed, 'tasks.push_failed', 'tasks.push_failed'],
    'an interrupted publish' => ['gateway:pr-when-done', OperationStatus::Interrupted, null, 'operation.interrupted'],
]);

it('skips a busy group quietly and stores nothing', function (): void {
    $world = TaskCopyDoneFixture::make();
    $world->group->update(['pr_mode' => TaskPrMode::PrWhenDone]);
    app()->instance(TaskExecutionLock::class, new class implements TaskExecutionLock
    {
        public function synchronized(int $groupId, Closure $operation, bool $wait = true): mixed
        {
            throw new ResourceOperationException('operation.target_busy', 'Another Task Operation owns this group.', 409);
        }
    });

    expect(whenDoneTick()[0])->toMatchArray(['result' => 'skipped', 'code' => 'operation.target_busy'])
        ->and($world->group->refresh()->publish_refusal)->toBeNull()
        ->and(TaskPublicationData::fromModel($world->group)?->state)->toBe('publishing')
        ->and(whenDonePublishes($world->group))->toBe(0);
});

it('reads done again under the group lock and refuses tasks.not_done when it changed', function (): void {
    $world = TaskCopyDoneFixture::make();
    $publication = TaskDonePublication::for($world->group, $world->check);
    Task::query()->create(['parent_id' => $world->group->id, 'position' => 2, 'title' => 'More', 'brief' => 'More.', 'status' => 'todo']);

    expect(fn () => app(TaskPublishIntent::class)->start($world->group->refresh(), $publication, 'test', null, $world->check->id))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('tasks.not_done')->and($exception->status)->toBe(409);
        });
    expect(whenDonePublishes($world->group))->toBe(0);
});

it('reports a fault of one group, shows it as refused, and goes on with the next', function (): void {
    Exceptions::fake();
    $broken = TaskCopyDoneFixture::make();
    $healthy = TaskCopyDoneFixture::make();
    Task::topLevel()->whereIn('id', [$broken->group->id, $healthy->group->id])->update(['pr_mode' => TaskPrMode::PrWhenDone->value]);
    Operation::creating(static function (Operation $operation) use ($broken): void {
        if ($operation->target_id === $broken->group->id) {
            throw new RuntimeException('The database refused the row.');
        }
    });

    $results = whenDoneTick();

    expect($results[0])->toMatchArray(['group' => $broken->group->id, 'result' => 'failed', 'code' => 'tasks.publish_failed'])
        ->and($results[1])->toMatchArray(['group' => $healthy->group->id, 'result' => 'admitted'])
        ->and($broken->group->refresh()->publish_refusal)->toMatchArray(['code' => 'tasks.publish_failed', 'check_id' => $broken->check->id])
        ->and(TaskPublicationData::fromModel($broken->group)?->state)->toBe('refused')
        ->and(TaskPublicationData::fromModel($broken->group)?->errorCode)->toBe('tasks.publish_failed');
    Exceptions::assertReported(RuntimeException::class);
});

it('keeps the validation error for a release of a group without a task copy', function (): void {
    $world = TaskCopyDoneFixture::make();
    $world->group->update(['taskable_type' => null, 'taskable_id' => null]);

    expect(fn () => app(StartTaskPublishAction::class)->execute($world->group->refresh(), new StartTaskPublishData, 'person', null))
        ->toThrow(function (ValidationException $exception): void {
            expect($exception->status)->toBe(422)->and(array_keys($exception->errors()))->toBe(['check']);
        });
});

it('cuts a long brief so the pull request body stays within the publish limit', function (): void {
    $world = TaskCopyDoneFixture::make();
    Task::topLevel()->whereKey($world->group->id)->update(['brief' => str_repeat('é', 70000)]);

    $body = ValidatedData::string(data_get(TaskDonePublication::for($world->group->refresh(), $world->check)->pull_request, 'body'));

    expect(mb_strlen($body))->toBeLessThanOrEqual(65536)
        ->and($body)->toContain('…')
        ->and($body)->toEndWith('Checked commit `'.TaskCopyDoneFixture::Commit.'`, handoff check '.$world->check->id.'.')
        ->and(Validator::make(['check' => $world->check->id, 'pull_request' => ['title' => 'Add the export', 'body' => $body]], (new StartTaskPublishRequest)->rules())->passes())->toBeTrue();
});

/** A lock that runs `$between` first, as another request would just before the admission takes the lock. */
function whenDoneLockWith(Closure $between): void
{
    app()->instance(TaskExecutionLock::class, new class($between) implements TaskExecutionLock
    {
        public function __construct(private Closure $between) {}

        public function synchronized(int $groupId, Closure $operation, bool $wait = true): mixed
        {
            ($this->between)($groupId);

            return $operation();
        }
    });
}

it('re-reads the mode under the row lock for the tick and refuses tasks.not_done when the group left pr_when_done', function (): void {
    $world = TaskCopyDoneFixture::make();
    $world->group->update(['pr_mode' => TaskPrMode::ReviewBeforePr]);

    expect(fn () => app(TaskPublishIntent::class)->start($world->group->refresh(), TaskDonePublication::for($world->group, $world->check), RunTaskPublishWhenDoneAction::Caller, null, $world->check->id, byItself: true))
        ->toThrow(ResourceOperationException::class, 'The task group no longer publishes by itself.');
    expect(whenDonePublishes($world->group))->toBe(0);
});

it('skips a group quietly when the re-check under the lock finds it no longer done, and publishes once the extra subtask is cancelled', function (): void {
    $world = TaskCopyDoneFixture::make();
    $world->group->update(['pr_mode' => TaskPrMode::PrWhenDone]);
    $extra = null;
    whenDoneLockWith(function (int $group) use (&$extra): void {
        $extra ??= Task::query()->create(['parent_id' => $group, 'position' => 2, 'title' => 'More', 'brief' => 'More.', 'status' => 'todo']);
    });

    expect(whenDoneTick()[0])->toMatchArray(['result' => 'skipped', 'code' => 'tasks.not_done'])
        ->and($world->group->refresh()->publish_refusal)->toBeNull();

    Task::query()->whereKey($extra?->id)->update(['status' => 'cancelled']);
    expect(whenDoneTick()[0]['result'])->toBe('admitted');
});

it('does not store a refusal over a publish that was admitted in between', function (): void {
    $world = TaskCopyDoneFixture::make();
    $world->group->update(['pr_mode' => TaskPrMode::PrWhenDone]);
    app()->instance(TaskExecutionLock::class, new class implements TaskExecutionLock
    {
        public function synchronized(int $groupId, Closure $operation, bool $wait = true): mixed
        {
            Operation::query()->create(['kind' => OperationKinds::TasksPublish, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $groupId, 'node_id' => null,
                'input' => ['check' => 1], 'status' => OperationStatus::Queued, 'triggered_by' => 'person', 'sequence' => 1, 'attempts' => 1]);

            throw new ResourceOperationException('tasks.copy_publish_held', 'Held.', 409);
        }
    });

    expect(whenDoneTick()[0])->toMatchArray(['result' => 'skipped', 'code' => 'tasks.copy_publish_held'])
        ->and($world->group->refresh()->publish_refusal)->toBeNull();
});

it('reads an empty ORBIT_TASKS_PR_MODE as the default', function (): void {
    $saved = [$_ENV['ORBIT_TASKS_PR_MODE'] ?? null, $_SERVER['ORBIT_TASKS_PR_MODE'] ?? null];
    $_ENV['ORBIT_TASKS_PR_MODE'] = $_SERVER['ORBIT_TASKS_PR_MODE'] = '';
    try {
        $config = require config_path('orbit.php');
    } finally {
        [$_ENV['ORBIT_TASKS_PR_MODE'], $_SERVER['ORBIT_TASKS_PR_MODE']] = $saved;
    }

    expect(data_get($config, 'tasks.pr_mode'))->toBe('review_before_pr');
});

it('takes a raw empty JSON object over HTTP as the release, and keeps the 422 on check for a group without a task copy', function (): void {
    $gateway = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit',
    ]));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    $world = TaskCopyDoneFixture::make(TaskPrMode::ReviewBeforePr);
    $post = fn (Task $group, string $key) => $this->call('POST', '/api/v1/tasks/'.$group->id.'/publish', [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => $key], '{}');

    $post($world->group, 'release-1')->assertStatus(202);
    expect(Operation::query()->where('kind', OperationKinds::TasksPublish)->sole()->input['check'])->toBe($world->check->id);

    $plain = Task::query()->create(['project_id' => $world->group->project_id, 'title' => 'VM group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Running]);
    $post($plain, 'release-2')->assertStatus(422)->assertJsonPath('error.code', 'validation.failed')->assertJsonStructure(['error' => ['details' => ['check']]]);
});

it('starts a done check again after its publish timed out on a lock, under the key of its attempt, and shows it publishing until the third', function (): void {
    $world = TaskCopyDoneFixture::make();
    $world->group->update(['pr_mode' => TaskPrMode::PrWhenDone]);
    $base = 'pr-when-done:'.$world->group->id.':'.$world->check->id;
    whenDoneTick();
    $first = whenDoneEndPublish($world->group);

    expect(TaskPublicationData::fromModel($world->group->refresh())?->state)->toBe('publishing')
        ->and(TaskPublicationData::fromModel($world->group)?->errorCode)->toBeNull();
    foreach ([2, 3] as $attempt) {
        $result = whenDoneTick()[0];
        $operation = Operation::query()->findOrFail($result['operation']);
        expect($result)->toMatchArray(['result' => 'admitted', 'code' => null])
            ->and($operation->idempotency_key)->toBe($base.':'.$attempt)
            ->and($operation->idempotency_fingerprint)->toBe(hash('sha256', "tasks.publish|{$world->group->id}|{$world->check->id}|{$attempt}"))
            ->and($operation->input['check'])->toBe($world->check->id)
            ->and($world->group->refresh()->publish_refusal)->toBeNull();
        $last = whenDoneEndPublish($world->group);
        expect(TaskPublicationData::fromModel($world->group->refresh())?->state)->toBe($attempt < 3 ? 'publishing' : 'refused');
    }

    $results = whenDoneTick();

    $group = $world->group->refresh();
    expect($first->idempotency_key)->toBe($base)
        ->and($results)->toBe([['group' => $group->id, 'check' => $world->check->id, 'result' => 'skipped', 'code' => 'tasks.publish_lock_timeout', 'operation' => $last->id]])
        ->and(whenDonePublishes($group))->toBe(3)
        ->and($group->publish_refusal)->toBeNull()
        ->and(TaskPublicationData::fromModel($group)?->state)->toBe('refused')
        ->and(TaskPublicationData::fromModel($group)?->errorCode)->toBe('tasks.publish_lock_timeout')
        ->and(TaskPublicationData::fromModel($group)?->operationId)->toBe($last->id);
});

it('starts no retry while the earlier publish of the group still runs', function (): void {
    $world = TaskCopyDoneFixture::make();
    $world->group->update(['pr_mode' => TaskPrMode::PrWhenDone]);
    whenDoneTick();
    $first = whenDoneEndPublish($world->group);
    $second = whenDoneTick()[0];

    // The retry still runs: a group with an open Operation is not done, so the tick passes it by and starts no third.
    expect($second)->toMatchArray(['result' => 'admitted'])->and($second['operation'])->not->toBe($first->id)
        ->and(whenDoneTick())->toBe([])
        ->and(whenDonePublishes($world->group))->toBe(2);

    // A hand publish that runs holds the retry the same way, and the tick stores nothing.
    whenDoneEndPublish($world->group);
    $hand = app(StartTaskPublishAction::class)->execute($world->group->refresh(), new StartTaskPublishData, 'person', null);
    expect(whenDoneTick())->toBe([])
        ->and(whenDonePublishes($world->group))->toBe(3)
        ->and($world->group->refresh()->publish_refusal)->toBeNull()
        ->and(TaskPublicationData::fromModel($world->group)?->state)->toBe('publishing')
        ->and(TaskPublicationData::fromModel($world->group)?->operationId)->toBe($hand->id);
});

it('admits a hand publish of a done check whose publish timed out on a lock, before and after the last retry', function (): void {
    $world = TaskCopyDoneFixture::make();
    $world->group->update(['pr_mode' => TaskPrMode::PrWhenDone]);
    whenDoneTick();
    whenDoneEndPublish($world->group);

    $hand = app(StartTaskPublishAction::class)->execute($world->group->refresh(), new StartTaskPublishData, 'person', null);
    expect($hand->triggered_by)->toBe('person')->and($hand->input['check'])->toBe($world->check->id);

    whenDoneEndPublish($world->group);
    whenDoneTick();
    whenDoneEndPublish($world->group);
    expect(TaskPublicationData::fromModel($world->group->refresh())?->state)->toBe('refused');

    $again = app(StartTaskPublishAction::class)->execute($world->group->refresh(), new StartTaskPublishData, 'person', null);
    expect($again->status->terminal())->toBeFalse()->and(whenDonePublishes($world->group))->toBe(4);
});

it('keeps a refusal after a lock timeout a refusal: the tick does not start that check again', function (string $code): void {
    $world = TaskCopyDoneFixture::make();
    $world->group->update(['pr_mode' => TaskPrMode::PrWhenDone]);
    whenDoneTick();
    whenDoneEndPublish($world->group);
    whenDoneTick();
    $refused = whenDoneEndPublish($world->group, $code);

    expect(whenDoneTick())->toBe([['group' => $world->group->id, 'check' => $world->check->id, 'result' => 'skipped', 'code' => $code, 'operation' => $refused->id]])
        ->and(whenDonePublishes($world->group))->toBe(2)
        ->and(TaskPublicationData::fromModel($world->group->refresh())?->state)->toBe('refused')
        ->and(TaskPublicationData::fromModel($world->group)?->errorCode)->toBe($code);
})->with(['tasks.publish_secret_content', 'tasks.push_failed', 'tasks.publish_failed']);

it('keeps a refused check refused when a later publish of it timed out on a lock', function (): void {
    $world = TaskCopyDoneFixture::make();
    $world->group->update(['pr_mode' => TaskPrMode::PrWhenDone]);
    whenDoneTick();
    $refused = whenDoneEndPublish($world->group, 'tasks.publish_secret_content');
    app(StartTaskPublishAction::class)->execute($world->group->refresh(), new StartTaskPublishData, 'person', null);
    whenDoneEndPublish($world->group);

    expect(whenDoneTick())->toBe([['group' => $world->group->id, 'check' => $world->check->id, 'result' => 'skipped', 'code' => 'tasks.publish_secret_content', 'operation' => $refused->id]])
        ->and(whenDonePublishes($world->group))->toBe(2)
        ->and(TaskPublicationData::fromModel($world->group->refresh())?->state)->toBe('refused')
        ->and(TaskPublicationData::fromModel($world->group)?->errorCode)->toBe('tasks.publish_secret_content');
});

it('shows a lock timeout of a group that the tick does not publish as refused at once', function (): void {
    $world = TaskCopyDoneFixture::make(TaskPrMode::ReviewBeforePr);
    app(StartTaskPublishAction::class)->execute($world->group->refresh(), new StartTaskPublishData, 'person', null);
    $timedOut = whenDoneEndPublish($world->group);

    $publication = TaskPublicationData::fromModel($world->group->refresh());
    expect($publication?->state)->toBe('refused')
        ->and($publication?->errorCode)->toBe('tasks.publish_lock_timeout')
        ->and($publication?->operationId)->toBe($timedOut->id)
        ->and(whenDoneTick()[0])->toMatchArray(['result' => 'waiting'])
        ->and(whenDonePublishes($world->group))->toBe(1);
});

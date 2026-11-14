<?php

declare(strict_types=1);

use App\Actions\Tasks\RunTaskPublishWhenDoneAction;
use App\Actions\Tasks\StartTaskPublishAction;
use App\Data\Tasks\StartTaskPublishData;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskDonePublication;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPrMode;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
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
        ->and($operation->input['pull_request']['title'])->toBe('Add the export')
        ->and($operation->input['pull_request']['draft'])->toBeFalse()
        ->and($operation->input['pull_request']['body'])->toContain('Export the board as CSV.')
        ->and($operation->input['pull_request']['body'])->toContain('- Write the export')
        ->and($operation->input['pull_request']['body'])->toContain('Checked commit `'.TaskCopyDoneFixture::Commit.'`, handoff check '.$world->check->id.'.')
        ->and(isset($operation->input['workspace']))->toBeTrue()
        ->and(app(OperationIdempotency::class)->key)->toBeNull();
});

it('replays the recorded publish of the same done check and never starts it twice, a failed run included', function (): void {
    $world = TaskCopyDoneFixture::make();
    $world->group->update(['pr_mode' => TaskPrMode::PrWhenDone]);
    whenDoneTick();
    $operation = Operation::query()->where('kind', OperationKinds::TasksPublish)->sole();
    $operation->update(['status' => OperationStatus::Failed, 'error_code' => 'tasks.push_failed', 'finished_at' => now()]);

    $results = whenDoneTick();

    expect($results)->toBe([['group' => $world->group->id, 'check' => $world->check->id, 'result' => 'replayed', 'code' => null, 'operation' => $operation->id]])
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
    $world->turn->update(['finished_at' => now()->addMinute()]);

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
    expect(json_decode(Artisan::output(), true)['groups'][0]['result'])->toBe('admitted');
});

it('releases a waiting group\'s done check without --check, with the same title and body and a ready pull request', function (): void {
    $world = TaskCopyDoneFixture::make(TaskPrMode::ReviewBeforePr);
    $world->group->update(['publish_refusal' => ['code' => 'tasks.copy_publish_held', 'check_id' => $world->check->id, 'at' => now()->toIso8601String()]]);

    $operation = app(StartTaskPublishAction::class)->execute($world->group->refresh(), new StartTaskPublishData, 'person', null);

    expect($operation->kind)->toBe(OperationKinds::TasksPublish)
        ->and($operation->input['check'])->toBe($world->check->id)
        ->and($operation->input['pull_request'])->toBe(TaskDonePublication::for($world->group, $world->check)->pull_request)
        ->and($operation->input['pull_request']['draft'])->toBeFalse()
        ->and($world->group->refresh()->publish_refusal)->toBeNull();
});

it('refuses the release while the work is not done', function (): void {
    $world = TaskCopyDoneFixture::make();
    $world->turn->update(['finished_at' => now()->addMinute()]);

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

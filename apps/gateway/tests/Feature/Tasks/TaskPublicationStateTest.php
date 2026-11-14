<?php

declare(strict_types=1);

use App\Actions\Tasks\ShowTasksStatusAction;
use App\Data\Tasks\TaskGroupData;
use App\Data\Tasks\TaskPublicationData;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tasks\TaskPrMode;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\SwampNodeFixture;
use Tests\Support\TaskCopyDoneFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    SwampNodeFixture::ensure();
});

function publicationPublish(TaskCopyDoneFixture $world, OperationStatus $status, ?string $code = null): Operation
{
    return Operation::query()->create(['kind' => OperationKinds::TasksPublish, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $world->group->id, 'node_id' => null,
        'input' => ['check' => $world->check->id, 'commit' => TaskCopyDoneFixture::Commit], 'status' => $status, 'error_code' => $code, 'sequence' => 1, 'attempts' => 1]);
}

it('reports each publication state of a task copy from its records', function (Closure $arrange, string $state, ?string $code, bool $withCheck): void {
    $world = TaskCopyDoneFixture::make(TaskPrMode::ReviewBeforePr);
    $operation = $arrange($world);

    $publication = TaskPublicationData::fromModel($world->group->refresh());

    expect($publication?->state)->toBe($state)
        ->and($publication?->errorCode)->toBe($code)
        ->and($publication?->checkId)->toBe($withCheck ? $world->check->id : null)
        ->and($publication?->operationId)->toBe($operation instanceof Operation ? $operation->id : null);
})->with([
    'waiting for release' => [fn (TaskCopyDoneFixture $w) => null, 'waiting_for_release', null, true],
    'working' => [fn (TaskCopyDoneFixture $w) => $w->turn->update(['finished_at' => now()->addMinute()]) && false, 'working', null, false],
    'refused at admission' => [fn (TaskCopyDoneFixture $w) => $w->group->update(['publish_refusal' => ['code' => 'tasks.copy_publish_held', 'check_id' => $w->check->id, 'at' => now()->toIso8601String()]]) && false,
        'refused', 'tasks.copy_publish_held', true],
    'refused run' => [fn (TaskCopyDoneFixture $w) => publicationPublish($w, OperationStatus::Failed, 'tasks.publish_secret_content'), 'refused', 'tasks.publish_secret_content', true],
    'publishing' => [fn (TaskCopyDoneFixture $w) => publicationPublish($w, OperationStatus::Running), 'publishing', null, true],
    'published' => [function (TaskCopyDoneFixture $w): Operation {
        $w->group->update(['pr_url' => 'https://github.com/example/trial/pull/7']);

        return publicationPublish($w, OperationStatus::Succeeded);
    }, 'published', null, true],
]);

it('reports a pr_when_done group whose done check recorded pr_when_done as publishing, the tick\'s to start', function (): void {
    $world = TaskCopyDoneFixture::make();
    $world->group->update(['pr_mode' => TaskPrMode::PrWhenDone]);

    expect(TaskPublicationData::fromModel($world->group->refresh())?->state)->toBe('publishing');
});

it('shows the mode, its source and the publication on task:show, and no publication for a group without a task copy', function (): void {
    $world = TaskCopyDoneFixture::make(TaskPrMode::ReviewBeforePr);
    $world->group->project->update(['task_pr_mode' => TaskPrMode::ReviewBeforePr]);
    $plain = Task::query()->create(['project_id' => Project::query()->sole()->id, 'title' => 'Plain', 'brief' => 'Brief', 'status' => 'running']);

    $shown = TaskGroupData::fromModel($world->group->refresh())->toArray();

    expect($shown['pr_mode'])->toBe('review_before_pr')
        ->and($shown['pr_mode_source'])->toBe('project')
        ->and($shown['publication'])->toMatchArray(['mode' => 'review_before_pr', 'mode_source' => 'project', 'state' => 'waiting_for_release', 'check_id' => $world->check->id,
            'commit' => TaskCopyDoneFixture::Commit, 'operation_id' => null, 'error_code' => null])
        ->and(TaskGroupData::fromModel($plain->refresh())->toArray()['publication'])->toBeNull();
});

it('lists the waiting and the refused task copies under releases in task:status', function (): void {
    $world = TaskCopyDoneFixture::make(TaskPrMode::ReviewBeforePr);

    $releases = app(ShowTasksStatusAction::class)->execute()->toArray()['releases'];

    expect($releases)->toBe([['id' => $world->group->id, 'project_id' => $world->group->project_id, 'project' => 'trial', 'project_code' => $world->group->project->code,
        'title' => 'Add the export', 'state' => 'waiting_for_release', 'check_id' => $world->check->id, 'commit' => TaskCopyDoneFixture::Commit, 'error_code' => null]]);

    publicationPublish($world, OperationStatus::Failed, 'tasks.push_failed');
    expect(data_get(app(ShowTasksStatusAction::class)->execute()->toArray(), 'releases.0'))->toMatchArray(['state' => 'refused', 'error_code' => 'tasks.push_failed']);

    $world->turn->update(['finished_at' => now()->addMinute()]);
    expect(app(ShowTasksStatusAction::class)->execute()->toArray()['releases'])->toBe([]);
});

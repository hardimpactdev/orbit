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
use Tests\Support\TaskDoneFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    SwampNodeFixture::ensure();
});

function publicationPublish(TaskDoneFixture $world, OperationStatus $status, ?string $code = null): Operation
{
    return Operation::query()->create(['kind' => OperationKinds::TasksPublish, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $world->group->id, 'node_id' => null,
        'input' => ['check' => $world->check->id, 'commit' => TaskDoneFixture::Commit], 'status' => $status, 'error_code' => $code, 'sequence' => 1, 'attempts' => 1]);
}

it('reports each publication state of a task copy from its records', function (Closure $arrange, string $state, ?string $code, bool $withCheck): void {
    $world = TaskDoneFixture::make(TaskPrMode::ReviewBeforePr);
    $operation = $arrange($world);

    $publication = TaskPublicationData::fromModel($world->group->refresh());

    expect($publication?->state)->toBe($state)
        ->and($publication?->errorCode)->toBe($code)
        ->and($publication?->checkId)->toBe($withCheck ? $world->check->id : null)
        ->and($publication?->operationId)->toBe($operation instanceof Operation ? $operation->id : null);
})->with([
    'waiting for release' => [fn (TaskDoneFixture $w) => null, 'waiting_for_release', null, true],
    'waiting for the pull request text' => [fn (TaskDoneFixture $w) => $w->check->update(['pr_text_operation_id' => null]) && false, 'waiting_for_pr_text', null, true],
    'working' => [fn (TaskDoneFixture $w) => $w->turn->update(['result' => ['state' => 'asking_for_input', 'ended' => true]]) && false, 'working', null, false],
    'refused at admission' => [fn (TaskDoneFixture $w) => $w->group->update(['publish_refusal' => ['code' => 'tasks.copy_publish_held', 'check_id' => $w->check->id, 'at' => now()->toIso8601String()]]) && false,
        'refused', 'tasks.copy_publish_held', true],
    'refused run' => [fn (TaskDoneFixture $w) => publicationPublish($w, OperationStatus::Failed, 'tasks.publish_secret_content'), 'refused', 'tasks.publish_secret_content', true],
    'publishing' => [fn (TaskDoneFixture $w) => publicationPublish($w, OperationStatus::Running), 'publishing', null, true],
    'published' => [function (TaskDoneFixture $w): Operation {
        $w->group->update(['pr_url' => 'https://github.com/example/trial/pull/7']);

        return publicationPublish($w, OperationStatus::Succeeded);
    }, 'published', null, true],
]);

it('reports a pr_when_done group whose done check recorded pr_when_done as publishing, the tick\'s to start', function (): void {
    $world = TaskDoneFixture::make();
    $world->group->update(['pr_mode' => TaskPrMode::PrWhenDone]);

    expect(TaskPublicationData::fromModel($world->group->refresh())?->state)->toBe('publishing');
});

it('shows the mode, its source and the publication on task:show, and the mode but no publication for a group without its copy yet', function (): void {
    $world = TaskDoneFixture::make(TaskPrMode::ReviewBeforePr);
    $world->group->project->update(['task_pr_mode' => TaskPrMode::ReviewBeforePr]);
    $plain = Task::query()->create(['project_id' => Project::query()->sole()->id, 'title' => 'Plain', 'brief' => 'Brief', 'status' => 'running']);

    $shown = TaskGroupData::fromModel($world->group->refresh())->toArray();

    expect($shown['pr_mode'])->toBe('review_before_pr')
        ->and($shown['pr_mode_source'])->toBe('project')
        ->and($shown['publication'])->toMatchArray(['mode' => 'review_before_pr', 'mode_source' => 'project', 'state' => 'waiting_for_release', 'check_id' => $world->check->id,
            'commit' => TaskDoneFixture::Commit, 'operation_id' => null, 'error_code' => null,
            'pr_text' => ['title' => TaskDoneFixture::Title, 'body' => TaskDoneFixture::Body, 'operation_id' => $world->turn->id]])
        ->and($shown['issue'])->toBeNull()
        ->and(TaskGroupData::fromModel($plain->refresh())->toArray())->toMatchArray(['pr_mode' => 'review_before_pr', 'pr_mode_source' => 'project', 'publication' => null]);
});

it('lists the waiting and the refused task copies under releases in task:status', function (): void {
    $world = TaskDoneFixture::make(TaskPrMode::ReviewBeforePr);

    $releases = app(ShowTasksStatusAction::class)->execute()->toArray()['releases'];

    expect($releases)->toBe([['id' => $world->group->id, 'project_id' => $world->group->project_id, 'project' => 'trial', 'project_code' => $world->group->project->code,
        'title' => 'Add the export', 'state' => 'waiting_for_release', 'check_id' => $world->check->id, 'commit' => TaskDoneFixture::Commit, 'error_code' => null]]);

    // A done group that waits for its reviewer's text is listed too: an older copy has no key and needs a person's publish.
    $world->check->update(['pr_text_operation_id' => null]);
    expect(data_get(app(ShowTasksStatusAction::class)->execute()->toArray(), 'releases.0.state'))->toBe('waiting_for_pr_text');
    $world->check->update(['pr_text_operation_id' => $world->turn->id]);

    publicationPublish($world, OperationStatus::Failed, 'tasks.push_failed');
    expect(data_get(app(ShowTasksStatusAction::class)->execute()->toArray(), 'releases.0'))->toMatchArray(['state' => 'refused', 'error_code' => 'tasks.push_failed']);

    $world->turn->update(['result' => ['state' => 'asking_for_input', 'ended' => true]]);
    expect(app(ShowTasksStatusAction::class)->execute()->toArray()['releases'])->toBe([]);
});

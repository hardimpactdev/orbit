<?php

declare(strict_types=1);

use App\Actions\Tasks\StoreTaskGroupCommentAction;
use App\Data\Tasks\StoreTaskGroupCommentData;
use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $gateway = Node::query()->create([
        'name' => 'assistance-gateway', 'status' => LifecycleStatus::Active,
        'platform' => 'linux', 'wireguard_ip' => '10.44.0.80', 'public_ssh_host' => '192.0.2.80',
    ]);
    $this->markAsGateway($gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    app(TaskExtensionState::class)->enable();
    $project = Project::query()->create(['name' => 'Assistance', 'slug' => 'assistance', 'repository_url' => 'https://github.com/example/app.git']);
    $this->group = Task::query()->create([
        'project_id' => $project->id, 'title' => 'Post-child gate', 'brief' => 'Publish retry.',
        'status' => 'running', 'orchestration' => 'external',
    ]);
    foreach (['completed', 'failed', 'cancelled'] as $position => $status) {
        $this->group->tasks()->create(['title' => $status, 'brief' => 'Ended work.', 'position' => $position + 1, 'status' => $status]);
    }
    $this->url = "/api/v1/task-groups/{$this->group->id}/comments";
});

function group_comment_payload(string $type = 'assistance_requested', string $body = 'Work item X, publish-retry, cycle 2, gate: may I republish?'): array
{
    return ['type' => $type, 'body' => $body, 'author' => 'driver'];
}

it('records and resolves group direction with readable isolated history and no effects', function (): void {
    Event::fake([RecordBroadcast::class]);
    Bus::fake();
    Queue::fake();
    Http::preventStrayRequests();
    $this->mock(TaskCheckRunner::class)->shouldNotReceive('start');
    $children = $this->group->tasks()->get()->map->getAttributes()->all();
    $child = $this->group->tasks()->firstOrFail();
    $child->comments()->create(['task_group_id' => $this->group->id, 'type' => 'resolution', 'body' => 'Child history.', 'author' => 'operator', 'posted_at' => now()]);

    $comment = $this->postJson($this->url, group_comment_payload(), ['X-Orbit-Request-Id' => '7705ac39-6499-4422-8511-fb2675b5eadc'])
        ->assertCreated()->assertJsonPath('data.task_group_id', $this->group->id)
        ->assertJsonPath('data.task_id', $this->group->id)->assertJsonPath('data.agent_thread_id', null)
        ->assertJsonPath('meta.request_id', '7705ac39-6499-4422-8511-fb2675b5eadc')->json('data');
    $stored = TaskComment::query()->findOrFail($comment['id']);
    expect($stored->task?->id)->toBe($this->group->id)
        ->and($stored->parent?->id)->toBe($this->group->id)
        ->and(Task::query()->find($this->group->id))->toBeNull();
    $this->getJson('/api/v1/task-groups/'.$this->group->id)->assertOk()
        ->assertJsonPath('data.assistance_requested', true)->assertJsonPath('data.assistance_kind', 'direction')
        ->assertJsonPath('data.assistance_question', group_comment_payload()['body']);
    $this->getJson('/api/v1/tasks/status')->assertOk()->assertJsonPath('data.assistance.0.id', $this->group->id);
    $state = $this->group->refresh()->only([...TaskAssistance::Fields, 'updated_at']);
    $events = Activity::query()->where('description', 'assistance requested')->count();
    $this->postJson($this->url, group_comment_payload())->assertCreated();
    expect($this->group->refresh()->only([...TaskAssistance::Fields, 'updated_at']))->toEqual($state)
        ->and(Activity::query()->where('description', 'assistance requested')->count())->toBe($events);
    $this->travel(1)->seconds();
    $this->postJson($this->url, group_comment_payload('resolution', 'Approved for this work item, stage and cycle.'))->assertCreated();
    $this->postJson($this->url, group_comment_payload('resolution', 'Approved for this work item, stage and cycle.'))->assertCreated();
    expect($this->group->refresh()->assistance_requested)->toBeFalse()
        ->and($this->group->status)->toBe(TaskGroupStatus::Running)
        ->and($this->group->tasks()->get()->map->getAttributes()->all())->toBe($children);
    $this->getJson('/api/v1/tasks/status')->assertOk()->assertJsonPath('data.assistance', []);
    $this->getJson($this->url)->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('data.0.type', 'resolution');
    $this->getJson($this->url.'?type=assistance_requested&limit=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'assistance_requested');
    $this->getJson("/api/v1/task-groups/{$this->group->id}/tasks/{$child->id}/comments")
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.body', 'Child history.');
    $this->group->update(['status' => TaskGroupStatus::Completed]);
    $this->getJson($this->url)->assertOk()->assertJsonCount(4, 'data');
    expect(Activity::query()->where('description', 'assistance cleared')->where('subject_id', $this->group->id)->firstOrFail()->properties)
        ->toMatchArray(['actor' => 'driver', 'task_group_id' => $this->group->id]);
    expect(Operation::query()->count())->toBe(0);
    Event::assertDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::TaskGroupUpdated && $event->id === $this->group->id);
    Event::assertDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::TaskCommentCreated && $event->data['task_id'] === $this->group->id);
    Bus::assertNothingDispatched();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('refuses writes to terminal or open-child groups without inserting a comment', function (string $case, string $code): void {
    if ($case === 'open') {
        $this->group->tasks()->create(['title' => 'New child', 'brief' => 'Appended.', 'position' => 4]);
    } else {
        $this->group->update(['status' => $case]);
        TaskAssistance::apply($this->group, AssistanceKind::RemovalFailure, reason: 'Workspace ownership refused.');
    }
    $before = $this->group->refresh()->getAttributes();
    foreach (['assistance_requested', 'resolution'] as $type) {
        $this->postJson($this->url, group_comment_payload($type))->assertStatus(409)->assertJsonPath('error.code', $code);
    }
    expect(TaskComment::query()->count())->toBe(0)->and($this->group->refresh()->getAttributes())->toBe($before);
})->with([
    ['open', 'tasks.assistance_subtask_open'],
    ['completed', 'tasks.group_closed'], ['cancelled', 'tasks.group_closed'], ['failed', 'tasks.group_closed'],
]);

it('validates the narrow group comment input', function (array $payload, string $field): void {
    $this->postJson($this->url, $payload)->assertUnprocessable()->assertJsonStructure(['error' => ['details' => [$field]]]);
    expect(TaskComment::query()->count())->toBe(0);
})->with([
    [group_comment_payload('resolution', ''), 'body'],
    [group_comment_payload('resolution', " \n\t"), 'body'],
    [group_comment_payload('assistance_requested', ' '), 'body'],
    [group_comment_payload('approved'), 'type'],
    [[...group_comment_payload(), 'author' => ''], 'author'],
    [[...group_comment_payload(), 'agent_thread_id' => 1], 'body'],
    [[...group_comment_payload(), 'receipt_hash' => 'untrusted'], 'body'],
]);

it('keeps safety failures and child requests when resolution is only a recorded answer', function (AssistanceKind $kind): void {
    TaskAssistance::apply($this->group, $kind, reason: 'Safety gate refused.');
    $child = $this->group->tasks()->firstOrFail();
    // Ended child requests are not valid direction owners, but can retain removal failures.
    TaskAssistance::apply($child, AssistanceKind::RemovalFailure, reason: 'Child cleanup refused.');
    $before = $this->group->refresh()->only(TaskAssistance::Fields);
    $childBefore = $child->refresh()->getAttributes();
    $this->postJson($this->url, group_comment_payload('resolution', 'A recorded answer, not a repair.'))->assertCreated();
    expect($this->group->refresh()->only(TaskAssistance::Fields))->toBe($before)
        ->and($child->refresh()->getAttributes())->toBe($childBefore);
})->with([AssistanceKind::Failure, AssistanceKind::RemovalFailure, AssistanceKind::Observation]);

it('uses explicit operator replacement for requests and retains the replacement on read', function (): void {
    TaskAssistance::apply($this->group, AssistanceKind::Failure, reason: 'Publish failed.');
    $this->postJson($this->url, group_comment_payload())->assertCreated();
    $this->postJson($this->url, group_comment_payload(body: 'Operator: stop and inspect the checked commit.'))->assertCreated();
    $this->getJson('/api/v1/task-groups/'.$this->group->id)->assertOk()
        ->assertJsonPath('data.assistance_question', 'Operator: stop and inspect the checked commit.');
    $this->getJson($this->url.'?type=assistance_requested')->assertOk()->assertJsonCount(2, 'data');
    expect($this->group->refresh()->assistance_kind)->toBe(AssistanceKind::Direction);
});

it('requires Gateway access for both comment routes and refuses missing groups', function (): void {
    $this->getJson('/api/v1/task-groups/999999/comments')->assertNotFound();
    $this->postJson('/api/v1/task-groups/999999/comments', group_comment_payload())->assertNotFound();
    $peer = Node::query()->create(['name' => 'no-access', 'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.81', 'public_ssh_host' => '192.0.2.81']);
    $this->withServerVariables(['REMOTE_ADDR' => $peer->wireguard_ip]);
    $this->getJson($this->url)->assertForbidden();
    $this->postJson($this->url, group_comment_payload())->assertForbidden();
    expect(TaskComment::query()->count())->toBe(0);
});

it('validates history filters and redacts assistance activity', function (): void {
    $this->postJson($this->url, group_comment_payload(body: 'Token token=ghp_abcdefghijklmnopqrstuvwxyz1234567890ABCD'))
        ->assertCreated();
    $this->getJson($this->url)->assertOk();
    expect(json_encode(Activity::query()->where('description', 'assistance requested')->firstOrFail()->properties))
        ->not->toContain('ghp_abcdefghijklmnopqrstuvwxyz1234567890ABCD');
    $this->getJson($this->url.'?limit=101')->assertUnprocessable();
    $this->getJson($this->url.'?type=unknown')->assertUnprocessable();
});

it('rechecks persisted guards rather than a previously bound group or loaded children', function (string $case, string $code): void {
    $bound = Task::topLevel()->with('tasks')->findOrFail($this->group->id);
    if ($case === 'closed') {
        $this->group->update(['status' => TaskGroupStatus::Completed]);

    } else {
        $this->group->tasks()->create(['title' => 'New work', 'brief' => 'Appended since binding.', 'position' => 4, 'status' => $case]);
    }
    try {
        app(StoreTaskGroupCommentAction::class)->execute($bound, new StoreTaskGroupCommentData(TaskCommentType::AssistanceRequested, 'May I publish?', 'driver'));
        $this->fail('A stale route model admitted an assistance comment.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe($code);
    }
    expect(TaskComment::query()->count())->toBe(0)->and($this->group->fresh()->assistance_requested)->toBeFalse();
})->with([
    ['closed', 'tasks.group_closed'],
    ['todo', 'tasks.assistance_subtask_open'], ['running', 'tasks.assistance_subtask_open'], ['reviewing', 'tasks.assistance_subtask_open'],
]);

it('keeps the extension gate on group comment reads and writes', function (): void {
    app(TaskExtensionState::class)->disable();
    $this->getJson($this->url)->assertConflict()->assertJsonPath('error.code', 'extension.disabled');
    $this->postJson($this->url, group_comment_payload())->assertConflict()->assertJsonPath('error.code', 'extension.disabled');
    expect(TaskComment::query()->count())->toBe(0);
});

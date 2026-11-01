<?php

declare(strict_types=1);

use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOperations;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;

beforeEach(function (): void {
    $project = Project::query()->create(['name' => 'Holder', 'slug' => 'holder', 'repository_url' => 'https://github.com/acme/holder.git']);
    $this->group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Work', 'brief' => 'Work', 'status' => TaskGroupStatus::Running]);
});

it('refuses another key before validation while a declared native group root is nonterminal', function (string $kind, OperationStatus $status): void {
    $holder = Operation::query()->create(['kind' => $kind, 'target_type' => 'task_group', 'target_id' => $this->group->id,
        'status' => $status, 'state' => [], 'input' => [], 'idempotency_key' => 'first']);
    $identity = app(OperationIdempotency::class);
    $identity->kind = 'tasks.park';
    $identity->key = 'second';

    expect(fn () => app(TaskOperations::class)->start($this->group, 'tasks.park', [],
        static fn () => throw new LogicException('Admission must refuse before validation.'), 'fixture', null))
        ->toThrow(function (ResourceOperationException $exception) use ($holder): void {
            expect($exception->errorCode)->toBe('operation.target_busy')->and($exception->status)->toBe(409)
                ->and($exception->details)->toBe(['operation_id' => $holder->id]);
        });
    expect(Operation::query()->count())->toBe(1);
})->with(['tasks.park', 'tasks.check'])->with([OperationStatus::Queued, OperationStatus::Running, OperationStatus::Waiting]);

it('replays the original key before checking the same native holder', function (): void {
    $holder = Operation::query()->create(['kind' => 'tasks.park', 'target_type' => 'task_group', 'target_id' => $this->group->id,
        'status' => OperationStatus::Running, 'state' => [], 'input' => [], 'idempotency_key' => 'same', 'idempotency_fingerprint' => 'same-input']);
    $identity = app(OperationIdempotency::class);
    $identity->kind = 'tasks.park';
    $identity->key = 'same';
    $identity->fingerprint = 'same-input';

    $replay = app(TaskOperations::class)->start($this->group, 'tasks.park', [],
        static fn () => throw new LogicException('Replay must not validate or start work.'), 'fixture', null);

    expect($replay->id)->toBe($holder->id)->and(Operation::query()->count())->toBe(1);
});

it('ignores terminal or unrelated records when admitting the group', function (string $case): void {
    $row = ['kind' => 'tasks.park', 'target_type' => 'task_group', 'target_id' => $this->group->id,
        'status' => OperationStatus::Running, 'state' => [], 'input' => []];
    if (in_array($case, ['succeeded', 'failed', 'cancelled', 'interrupted'], true)) {
        $row['status'] = OperationStatus::from($case);
        $row['lock_key'] = 'task_group:'.$this->group->id;
    } elseif ($case === 'other group') {
        $other = Task::topLevel()->create(['project_id' => $this->group->project_id, 'title' => 'Other', 'brief' => 'Other', 'status' => TaskGroupStatus::Running]);
        $row['target_id'] = $other->id;
    } elseif ($case === 'other target type') {
        $row['target_type'] = 'instance';
    } else {
        $row['kind'] = $case === 'undeclared kind' ? 'tasks.removed_kind' : 'github.app_sync';
    }
    Operation::query()->create($row);

    expect(fn () => app(TaskOperations::class)->start($this->group, 'tasks.park', [],
        static fn () => throw new ResourceOperationException('fixture.validation_reached', 'No holder prevented validation.'), 'fixture', null))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('fixture.validation_reached'));
})->with(['succeeded', 'failed', 'cancelled', 'interrupted', 'other group', 'other target type', 'undeclared kind', 'Gateway-only kind']);

it('cancels the unstarted native root for the requested subtask without a writer stamp', function (): void {
    app(TaskExtensionState::class)->enable();
    $holder = Operation::query()->create(['kind' => 'tasks.check', 'target_type' => 'task_group', 'target_id' => $this->group->id,
        'status' => OperationStatus::Queued, 'state' => [], 'input' => ['subtask' => 42], 'attempts' => 0]);

    app(TaskOperations::class)->cancelOpen($this->group, 42);

    expect($holder->fresh()->status)->toBe(OperationStatus::Cancelled)->and($holder->fresh()->cancel_requested_at)->not->toBeNull();
});

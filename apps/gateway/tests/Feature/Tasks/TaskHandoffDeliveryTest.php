<?php

declare(strict_types=1);

use App\Actions\Tasks\StartTaskTurnAction;
use App\Data\Tasks\TaskTurnData;
use App\Domain\Instances\ContainerServer;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckProjection;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskHandoffs;
use App\Domain\Tasks\TaskHandoffType;
use App\Domain\Tasks\TaskStatus;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\InstanceRuntime;
use App\Models\Operation;
use App\Models\TaskCheck;
use App\Models\TaskHandoff;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SwampNodeFixture;
use Tests\Support\TaskDoneFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    SwampNodeFixture::ensure();
    Queue::fake([StartAdmittedSwampRun::class]);
    app(TaskExtensionState::class)->enable();
});

const HandedHead = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

const HandedTree = 'cccccccccccccccccccccccccccccccccccccccc';

/** A gated group whose subtask runs, with its implementer's turn that ended STATE and left RECEIPT. */
function deliveryWorld(mixed $receipt, bool $gated = true): TaskDoneFixture
{
    $world = TaskDoneFixture::make(text: false);
    $world->group->update(['handoffs_gated' => $gated]);
    $world->subtask->update(['status' => TaskStatus::Running]);
    deliveryTurn($world, $receipt);

    return $world;
}

/** An implementer turn of the world's subtask that ended STATE and left RECEIPT. */
function deliveryTurn(TaskDoneFixture $world, mixed $receipt, string $state = 'done', OperationStatus $status = OperationStatus::Succeeded): Operation
{
    return Operation::query()->create(['kind' => OperationKinds::TasksCopyTurn, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $world->group->id,
        'node_id' => null, 'input' => ['subtask' => $world->subtask->id, 'role' => 'implementer', 'thread_id' => $world->reviewer->id + 100],
        'status' => $status, 'sequence' => 1, 'attempts' => 1, 'finished_at' => now(),
        'result' => ['state' => $state, 'ended' => true, 'receipts' => ['delivery' => $receipt, 'review' => null]]]);
}

/**
 * The delivery receipt of the copy turn's result, as `bin/task-check` writes it and the turn validates it.
 *
 * @param  list<array{0: string, 1: string}>  $parts
 * @return array<string, mixed>
 */
function deliveryReceipt(string $tree, array $parts, string $commit = HandedHead): array
{
    return ['commit' => $commit, 'tree' => $tree, 'parts' => array_map(static fn (array $part): array => ['name' => $part[0], 'result' => $part[1], 'seconds' => 1.5], $parts)];
}

/** The handoff check of the world's subtask ends with this result, as the check workflows write it. */
function deliveryChecked(TaskDoneFixture $world, string $status, array $result): TaskCheck
{
    $check = TaskCheck::query()->create(['task_id' => $world->subtask->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Running, 'pid' => 1,
        'process_started' => 'x', 'head_before' => '', 'tree_before' => '', 'started_at' => now()]);
    $operation = deliveryCheckOperation($world, $check);
    app(TaskCheckProjection::class)->apply($operation, ['run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => $status,
        'started_at' => now()->subMinute()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['instance_receipt' => ['result_json' => json_encode(['check_id' => $check->id, ...$result], JSON_THROW_ON_ERROR)]]]);

    return $check->refresh();
}

function deliveryCheckOperation(TaskDoneFixture $world, TaskCheck $check): Operation
{
    return Operation::query()->create(['kind' => OperationKinds::TasksCheck, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $world->group->id,
        'node_id' => null, 'input' => ['subtask' => $world->subtask->id, 'phase' => 'handoff', 'check_id' => $check->id, 'group_id' => $world->group->id],
        'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 1, 'state' => ['swamp_run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a']]);
}

/** @param list<array{0: string, 1: string}> $parts */
function deliveryPassed(array $parts = [['pest', 'passed']], string $outcome = 'passed'): array
{
    return ['outcome' => $outcome, 'exit_code' => $outcome === 'passed' ? 0 : 1, 'output' => '', 'head_before' => str_repeat('e', 40), 'handed_off_head' => HandedHead, 'handed_off_tree' => HandedTree,
        'tree_before' => str_repeat('b', 40), 'head' => str_repeat('e', 40), 'tree' => str_repeat('b', 40), 'changed_paths' => [], 'failed_step' => null,
        'evidence' => null, 'phase' => 'handoff', 'deliverables' => [], 'commit' => TaskDoneFixture::Commit, 'ci' => null,
        'parts' => array_map(static fn (array $part): array => ['name' => $part[0], 'result' => $part[1], 'seconds' => 2.0], $parts)];
}

it('accepts a delivery whose receipt names the handed-off tree with no failed part, when the check passed', function (): void {
    $world = deliveryWorld(deliveryReceipt(HandedTree, [['ci-plan', 'passed'], ['pest', 'passed']]));

    $check = deliveryChecked($world, 'succeeded', deliveryPassed());

    $row = TaskHandoff::query()->sole();
    expect($row->type)->toBe(TaskHandoffType::Delivery)
        ->and($row->rejected)->toBeFalse()
        ->and($row->rejection_reason)->toBe('')
        ->and($row->task_id)->toBe($world->subtask->id)
        ->and($row->task_check_id)->toBe($check->id)
        ->and($row->operation_id)->toBe(Operation::query()->where('input->role', 'implementer')->sole()->id)
        ->and($row->receipt['tree'])->toBe(HandedTree)
        ->and($row->created_at)->not->toBeNull();
});

it('rejects a delivery with one reason', function (mixed $receipt, string $status, array $result, string $reason): void {
    $world = deliveryWorld($receipt);

    deliveryChecked($world, $status, $result);

    expect(TaskHandoff::query()->sole()->only(['rejected', 'rejection_reason', 'receipt']))
        ->toBe(['rejected' => true, 'rejection_reason' => $reason, 'receipt' => $reason === TaskHandoffs::NoReceipt ? null : $receipt]);
})->with([
    'no receipt' => [null, 'succeeded', deliveryPassed(), TaskHandoffs::NoReceipt],
    'a receipt the turn found invalid' => ['invalid', 'succeeded', deliveryPassed(), TaskHandoffs::NoReceipt],
    'a receipt without a tree' => [['commit' => str_repeat('a', 40), 'parts' => [['name' => 'pest', 'result' => 'passed', 'seconds' => 1]]], 'succeeded', deliveryPassed(), TaskHandoffs::NoReceipt],
    'a receipt with no part' => [deliveryReceipt(HandedTree, []), 'succeeded', deliveryPassed(), TaskHandoffs::NoReceipt],
    'a receipt whose part has an unknown result' => [deliveryReceipt(HandedTree, [['pest', 'failed']]), 'succeeded', deliveryPassed(), TaskHandoffs::NoReceipt],
    'a receipt for another commit of the same tree' => [deliveryReceipt(HandedTree, [['pest', 'passed']], str_repeat('b', 40)), 'succeeded', deliveryPassed(), TaskHandoffs::OtherCommit],
    'a passed check that observed no handed-off work' => [deliveryReceipt(HandedTree, [['pest', 'passed']]), 'succeeded',
        array_diff_key(deliveryPassed(), ['handed_off_head' => true, 'handed_off_tree' => true]), TaskHandoffs::CheckFailed],
    'a receipt for another tree' => [deliveryReceipt(str_repeat('f', 40), [['pest', 'passed']]), 'succeeded', deliveryPassed(), TaskHandoffs::OtherCommit],
    'a receipt that shows a failed part' => [deliveryReceipt(HandedTree, [['pest', 'FAILED']]), 'succeeded', deliveryPassed(), TaskHandoffs::FailedPart],
    'a failed check' => [deliveryReceipt(HandedTree, [['pest', 'passed']]), 'succeeded', deliveryPassed([['pest', 'passed'], ['docs-lint', 'FAILED']], 'failed'), TaskHandoffs::CheckFailed],
    'a failed check that observed no handed-off tree' => [deliveryReceipt(HandedTree, [['pest', 'passed']]), 'failed',
        ['error_code' => 'tasks.repository_execution_unknown'], TaskHandoffs::CheckFailed],
    'a check conflict' => [deliveryReceipt(HandedTree, [['pest', 'passed']]), 'failed', ['error_code' => 'tasks.check_conflict', 'handed_off_head' => HandedHead, 'handed_off_tree' => HandedTree,
        'base' => 'current', 'default_commit' => str_repeat('9', 40), 'merged_default' => null, 'conflicting_paths' => ['a.php'], 'conflicting_more' => 0], TaskHandoffs::CheckFailed],
    'a passed part that failed in the check' => [deliveryReceipt(HandedTree, [['pest', 'passed']]), 'succeeded', deliveryPassed([['pest', 'FAILED']], 'failed'), TaskHandoffs::ClaimDisproved],
]);

it('rejects the delivery and ends the check failed when Swamp refuses to start it', function (): void {
    $world = deliveryWorld(deliveryReceipt(HandedTree, [['pest', 'passed']]));
    $check = TaskCheck::query()->create(['task_id' => $world->subtask->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Running, 'pid' => 1,
        'process_started' => 'x', 'head_before' => '', 'tree_before' => '', 'started_at' => now()]);

    app(TaskCheckProjection::class)->refused(deliveryCheckOperation($world, $check));

    expect(TaskHandoff::query()->sole()->only(['rejected', 'rejection_reason', 'task_check_id']))
        ->toBe(['rejected' => true, 'rejection_reason' => TaskHandoffs::CheckFailed, 'task_check_id' => $check->id])
        // The check ends failed, as its Operation did.
        ->and($check->refresh()->status)->toBe(TaskCheckStatus::Failed)
        ->and($check->finished_at)->not->toBeNull();
});

it('writes one row per delivery: none for a second check of the same turn, before the first turn, or after a newer question or failed turn', function (): void {
    $world = deliveryWorld(deliveryReceipt(HandedTree, [['pest', 'passed']]));
    deliveryChecked($world, 'succeeded', deliveryPassed([['pest', 'FAILED']], 'failed'));
    deliveryChecked($world, 'succeeded', deliveryPassed());
    expect(TaskHandoff::query()->count())->toBe(1);

    deliveryTurn($world, deliveryReceipt(HandedTree, [['pest', 'passed']]), 'asking_for_input');
    deliveryChecked($world, 'succeeded', deliveryPassed());
    deliveryTurn($world, deliveryReceipt(HandedTree, [['pest', 'passed']]), 'failed', OperationStatus::Failed);
    deliveryChecked($world, 'succeeded', deliveryPassed());
    expect(TaskHandoff::query()->count())->toBe(1);

    $fresh = deliveryWorld(null);
    Operation::query()->where('target_id', $fresh->group->id)->where('input->role', 'implementer')->delete();
    deliveryChecked($fresh, 'succeeded', deliveryPassed());
    expect(TaskHandoff::query()->count())->toBe(1);
});

it('writes no row for a group that left backlog before the gates, nor for a cancelled check', function (bool $gated, string $status): void {
    $world = deliveryWorld(deliveryReceipt(HandedTree, [['pest', 'passed']]), $gated);

    deliveryChecked($world, $status, deliveryPassed());

    expect(TaskHandoff::query()->count())->toBe(0);
})->with(['an ungated group' => [false, 'succeeded'], 'a cancelled check' => [true, 'cancelled']]);

it('refuses a reviewer turn unless the latest delivery is accepted for the commit under review', function (): void {
    $world = deliveryWorld(deliveryReceipt(HandedTree, [['pest', 'FAILED']]));
    // A copy whose container runs T3, so an admitted reviewer turn names its program.
    $runtime = InstanceRuntime::query()->where('instance_id', $world->instance->id)->sole();
    $runtime->delete();
    InstanceRuntime::query()->create([...$runtime->only(['node_id', 'power_state', 'runtime_version', 'agent_version', 'instance_id', 'incus_project', 'container_name',
        'bridge_address', 'seed_commit']), 't3_port' => 3800, 'container_server' => ContainerServer::T3]);
    $review = fn (TaskCheck $check): Operation => app(StartTaskTurnAction::class)->execute($world->group->refresh(),
        TaskTurnData::from(['subtask' => $world->subtask->id, 'role' => 'reviewer', 'check' => $check->id, 'prompt' => 'Review.']), 'test', null);
    $refusal = function (Closure $start): string {
        try {
            // An admitted turn ends at once, so the next admission finds the group free.
            $start()->forceFill(['status' => OperationStatus::Succeeded, 'finished_at' => now(), 'lock_key' => null,
                'result' => ['state' => 'done', 'ended' => true]])->save();
        } catch (ResourceOperationException $error) {
            return $error->errorCode.' '.$error->status;
        }

        return 'admitted';
    };

    expect($refusal(fn () => $review($world->check)))->toBe('tasks.handoff_required 409');

    // A failed check with its rejected delivery: the delivery gate answers before candidate eligibility.
    $rejected = deliveryChecked($world, 'succeeded', deliveryPassed([['pest', 'FAILED']], 'failed'));
    expect(TaskHandoff::query()->sole()->rejected)->toBeTrue()
        ->and($rejected->status)->toBe(TaskCheckStatus::Failed)
        ->and($refusal(fn () => $review($rejected)))->toBe('tasks.handoff_required 409');

    deliveryTurn($world, deliveryReceipt(HandedTree, [['pest', 'passed']]));
    $accepted = deliveryChecked($world, 'succeeded', deliveryPassed());
    expect(TaskHandoff::query()->latest('id')->first()?->rejected)->toBeFalse()
        ->and($refusal(fn () => $review($accepted)))->toBe('admitted');

    // Another eligible check of the same commit is that delivery's commit under review; an ungated group keeps the old rule.
    $again = TaskDoneFixture::check($world->group, $world->subtask, TaskDoneFixture::Commit);
    expect($refusal(fn () => $review($again)))->toBe('admitted');
    $world->group->update(['handoffs_gated' => false]);
    expect($refusal(fn () => $review($rejected)))->toBe('tasks.commit_not_checked 422');
});

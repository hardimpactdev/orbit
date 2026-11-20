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

const HandedOff = 'cccccccccccccccccccccccccccccccccccccccc';

/** A gated group whose subtask runs, with its implementer's finished turn that left RECEIPT, or none. */
function deliveryWorld(?string $receipt, bool $gated = true): TaskDoneFixture
{
    $world = TaskDoneFixture::make(text: false);
    $world->group->update(['handoffs_gated' => $gated]);
    $world->subtask->update(['status' => TaskStatus::Running]);
    Operation::query()->create(['kind' => OperationKinds::TasksCopyTurn, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $world->group->id,
        'node_id' => null, 'input' => ['subtask' => $world->subtask->id, 'role' => 'implementer', 'thread_id' => $world->reviewer->id + 100],
        'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 1, 'finished_at' => now(),
        'result' => ['state' => 'done', 'ended' => true, 'receipts' => ['delivery' => $receipt]]]);

    return $world;
}

/** @param list<array{0: string, 1: string}> $parts */
function deliveryReceipt(string $commit, array $parts): string
{
    return json_encode(['commit' => $commit, 'parts' => array_map(static fn (array $part): array => ['name' => $part[0], 'result' => $part[1], 'seconds' => 1.5], $parts)], JSON_THROW_ON_ERROR);
}

/** The handoff check of the world's subtask ends with this result, as the check workflows write it. */
function deliveryChecked(TaskDoneFixture $world, string $status, array $result): TaskCheck
{
    $check = TaskCheck::query()->create(['task_id' => $world->subtask->id, 'kind' => TaskCheckKind::Handoff, 'status' => TaskCheckStatus::Running, 'pid' => 1,
        'process_started' => 'x', 'head_before' => '', 'tree_before' => '', 'started_at' => now()]);
    $operation = Operation::query()->create(['kind' => OperationKinds::TasksCheck, 'target_type' => OperationKinds::TaskGroupTarget, 'target_id' => $world->group->id,
        'node_id' => null, 'input' => ['subtask' => $world->subtask->id, 'phase' => 'handoff', 'check_id' => $check->id, 'group_id' => $world->group->id],
        'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 1, 'state' => ['swamp_run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a']]);
    app(TaskCheckProjection::class)->apply($operation, ['run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => $status,
        'started_at' => now()->subMinute()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['instance_receipt' => ['result_json' => json_encode(['check_id' => $check->id, ...$result], JSON_THROW_ON_ERROR)]]]);

    return $check->refresh();
}

/** @param list<array{0: string, 1: string}> $parts */
function deliveryPassed(array $parts = [['pest', 'passed']], string $outcome = 'passed'): array
{
    return ['outcome' => $outcome, 'exit_code' => $outcome === 'passed' ? 0 : 1, 'output' => '', 'head_before' => str_repeat('e', 40), 'handed_off' => HandedOff,
        'tree_before' => str_repeat('b', 40), 'head' => str_repeat('e', 40), 'tree' => str_repeat('b', 40), 'changed_paths' => [], 'failed_step' => null,
        'evidence' => null, 'phase' => 'handoff', 'deliverables' => [], 'commit' => TaskDoneFixture::Commit, 'ci' => null,
        'parts' => array_map(static fn (array $part): array => ['name' => $part[0], 'result' => $part[1], 'seconds' => 2.0], $parts)];
}

it('accepts a delivery whose receipt names the handed-off commit with no failed part, when the check passed', function (): void {
    $world = deliveryWorld(deliveryReceipt(HandedOff, [['ci-plan', 'passed'], ['pest', 'passed']]));

    $check = deliveryChecked($world, 'succeeded', deliveryPassed());

    $row = TaskHandoff::query()->sole();
    expect($row->type)->toBe(TaskHandoffType::Delivery)
        ->and($row->rejected)->toBeFalse()
        ->and($row->rejection_reason)->toBe('')
        ->and($row->task_id)->toBe($world->subtask->id)
        ->and($row->task_check_id)->toBe($check->id)
        ->and($row->operation_id)->toBe(Operation::query()->where('input->role', 'implementer')->sole()->id)
        ->and($row->receipt['commit'])->toBe(HandedOff)
        ->and($row->created_at)->not->toBeNull();
});

it('rejects a delivery with one reason', function (?string $receipt, string $status, array $result, string $reason): void {
    $world = deliveryWorld($receipt);

    deliveryChecked($world, $status, $result);

    expect(TaskHandoff::query()->sole()->only(['rejected', 'rejection_reason']))->toBe(['rejected' => true, 'rejection_reason' => $reason]);
})->with([
    'no receipt' => [null, 'succeeded', deliveryPassed(), TaskHandoffs::NoReceipt],
    'a receipt that is not one' => ['{"commit": 1}', 'succeeded', deliveryPassed(), TaskHandoffs::NoReceipt],
    'a receipt for another commit' => [deliveryReceipt(str_repeat('f', 40), [['pest', 'passed']]), 'succeeded', deliveryPassed(), TaskHandoffs::OtherCommit],
    'a receipt that shows a failed part' => [deliveryReceipt(HandedOff, [['pest', 'FAILED']]), 'succeeded', deliveryPassed(), TaskHandoffs::FailedPart],
    'a failed check' => [deliveryReceipt(HandedOff, [['pest', 'passed']]), 'succeeded', deliveryPassed([['pest', 'passed'], ['docs-lint', 'FAILED']], 'failed'), TaskHandoffs::CheckFailed],
    'a check conflict' => [deliveryReceipt(HandedOff, [['pest', 'passed']]), 'failed', ['error_code' => 'tasks.check_conflict', 'handed_off' => HandedOff,
        'base' => 'current', 'default_commit' => str_repeat('9', 40), 'merged_default' => null, 'conflicting_paths' => ['a.php'], 'conflicting_more' => 0], TaskHandoffs::CheckFailed],
    'a passed part that failed in the check' => [deliveryReceipt(HandedOff, [['pest', 'passed']]), 'succeeded', deliveryPassed([['pest', 'FAILED']], 'failed'), TaskHandoffs::ClaimDisproved],
]);

it('writes no row for a group that left backlog before the gates, nor for a cancelled check', function (bool $gated, string $status): void {
    $world = deliveryWorld(deliveryReceipt(HandedOff, [['pest', 'passed']]), $gated);

    deliveryChecked($world, $status, deliveryPassed());

    expect(TaskHandoff::query()->count())->toBe(0);
})->with(['an ungated group' => [false, 'succeeded'], 'a cancelled check' => [true, 'cancelled']]);

it('refuses a reviewer turn unless the latest delivery is accepted for the check under review', function (): void {
    $world = deliveryWorld(deliveryReceipt(HandedOff, [['pest', 'FAILED']]));
    // A copy whose container runs T3, so an admitted reviewer turn names its program.
    $runtime = InstanceRuntime::query()->where('instance_id', $world->instance->id)->sole();
    $runtime->delete();
    InstanceRuntime::query()->create([...$runtime->only(['node_id', 'power_state', 'runtime_version', 'agent_version', 'instance_id', 'incus_project', 'container_name',
        'bridge_address', 'seed_commit']), 't3_port' => 3800, 'container_server' => ContainerServer::T3]);
    $review = fn (TaskCheck $check): Operation => app(StartTaskTurnAction::class)->execute($world->group->refresh(),
        TaskTurnData::from(['subtask' => $world->subtask->id, 'role' => 'reviewer', 'check' => $check->id, 'prompt' => 'Review.']), 'test', null);
    $refusal = function (Closure $start): string {
        try {
            $start();
        } catch (ResourceOperationException $error) {
            return $error->errorCode;
        }

        return 'admitted';
    };

    expect($refusal(fn () => $review($world->check)))->toBe('tasks.handoff_required');

    $rejected = deliveryChecked($world, 'succeeded', deliveryPassed());
    $rejected->update(['commit_sha' => TaskDoneFixture::Commit, 'status' => TaskCheckStatus::Passed]);
    expect(TaskHandoff::query()->sole()->rejected)->toBeTrue()
        ->and($refusal(fn () => $review($rejected)))->toBe('tasks.handoff_required');

    Operation::query()->where('input->role', 'implementer')->sole()->update(['result' => ['state' => 'done', 'ended' => true,
        'receipts' => ['delivery' => deliveryReceipt(HandedOff, [['pest', 'passed']])]]]);
    $accepted = deliveryChecked($world, 'succeeded', deliveryPassed());
    expect(TaskHandoff::query()->latest('id')->first()?->rejected)->toBeFalse()
        ->and($refusal(fn () => $review($rejected)))->toBe('tasks.handoff_required')
        ->and($refusal(fn () => $review($accepted)))->toBe('admitted');
});

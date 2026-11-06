<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofGroup;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Jobs\Proof\CancelProofGroup;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

/** A running run of one command (a `proof.command`) on the group that carries it. */
function proofRunning(): Operation
{
    $group = Task::query()->create(['title' => 'Proof b3f1c0de0000', 'brief' => 'Prove it', 'status' => TaskGroupStatus::Running]);
    $group->tasks()->create(['title' => 'Run the proof cases', 'brief' => 'Prove it', 'status' => TaskStatus::Running, 'position' => 1]);
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'swamp', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.9', 'wireguard_ip' => '10.44.0.9', 'user' => 'orbit']));

    return Operation::query()->create(['kind' => 'proof.command', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $node->id,
        'input' => ['commit' => str_repeat('a', 40), 'uncovered' => [], 'group_id' => $group->id, 'cases' => [
            ['command' => 'a:one', 'file' => 'swamp/proof/a/one.cases.yaml']]],
        'state' => [], 'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 0]);
}

it('cancels the group of each run of a proof that is cancelled', function (): void {
    Queue::fake();
    $run = proofRunning();
    $proof = Operation::query()->create(['kind' => 'proof.run', 'target_type' => 'node', 'target_id' => $run->node_id, 'node_id' => $run->node_id,
        'input' => ['commit' => str_repeat('a', 40), 'uncovered' => [], 'runs' => []],
        'state' => [], 'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 0]);
    // The run names its proof. The proof's own run list is empty, as when the request that admitted it stopped.
    $run->update(['input' => [...$run->input, 'proof_operation_id' => $proof->id]]);

    app(CancelOperationAction::class)->execute($proof);

    expect($proof->refresh()->status)->toBe(OperationStatus::Cancelled);
    Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $run->id && $job->trigger === 'proof-run');
});

it('cancels the group of a run that another proof admitted for the same commit only when that proof is cancelled', function (): void {
    Queue::fake();
    $other = proofRunning();
    $proof = Operation::query()->create(['kind' => 'proof.run', 'target_type' => 'node', 'target_id' => $other->node_id, 'node_id' => $other->node_id,
        'input' => ['commit' => str_repeat('a', 40), 'uncovered' => [], 'runs' => []], 'state' => [], 'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 0]);
    // Same commit, same command, same title: only the proof that the run names owns it.
    $other->update(['input' => [...$other->input, 'proof_operation_id' => $proof->id + 100]]);

    app(CancelOperationAction::class)->execute($proof);

    Queue::assertNotPushed(CancelProofGroup::class);
});

it('cancels a group only for the Operation that owns it', function (string $kind, string $targetType): void {
    $run = proofRunning();
    $stranger = Operation::query()->create(['kind' => $kind, 'target_type' => $targetType, 'target_id' => $run->target_id, 'node_id' => $run->node_id,
        'input' => [], 'state' => [], 'status' => OperationStatus::Cancelled, 'sequence' => 1, 'attempts' => 0]);

    // The group is running and the tasks extension is off: a cancel that reached its admission would be refused with extension.disabled.
    app(ProofGroup::class)->cancel($stranger);

    expect(Task::topLevel()->findOrFail($run->target_id)->status)->toBe(TaskGroupStatus::Running)
        ->and(Operation::query()->where('kind', 'tasks.cancel')->count())->toBe(0);
})->with([
    'a proof.run that targets a node with the id of a group' => ['proof.run', 'node'],
    'a script of the group' => ['tasks.script', 'task_group'],
    'a proof.command that targets something else' => ['proof.command', 'node'],
]);

it('names the caller of a cancel: the proof run, or the image refresh', function (): void {
    expect((new CancelProofGroup(1))->trigger)->toBe('proof-run')->and((new CancelProofGroup(1, 'image-refresh'))->trigger)->toBe('image-refresh');
});

it('cancels the group of a task image refresh that is cancelled before its run starts, so its VM does not keep a slot', function (): void {
    Queue::fake();
    $run = proofRunning();
    $refresh = Operation::query()->create(['kind' => 'task_vm.image_refresh', 'target_type' => 'task_group', 'target_id' => $run->target_id, 'node_id' => $run->node_id,
        'input' => ['group_id' => $run->target_id], 'state' => [], 'status' => OperationStatus::Queued, 'sequence' => 1, 'attempts' => 0]);

    app(CancelOperationAction::class)->execute($refresh);

    expect($refresh->refresh()->status)->toBe(OperationStatus::Cancelled);
    Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $refresh->id && $job->trigger === 'image-refresh');
});

it('does not touch the group when another operation is cancelled', function (): void {
    Queue::fake();
    $proof = proofRunning();
    $other = Operation::query()->create(['kind' => 'tasks.script', 'target_type' => 'task_group', 'target_id' => $proof->target_id, 'node_id' => $proof->node_id,
        'input' => [], 'state' => [], 'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 0]);

    app(CancelOperationAction::class)->execute($other);

    Queue::assertNotPushed(CancelProofGroup::class);
});

it('admits the cancel of the group of a cancelled proof, once, and not for a group that is already cancelled', function (): void {
    $proof = proofRunning();
    $proof->update(['status' => OperationStatus::Cancelled]);
    $groups = app(ProofGroup::class);
    $code = static function () use ($groups, $proof): string {
        try {
            $groups->cancel($proof);
        } catch (ResourceOperationException $exception) {
            return $exception->errorCode;
        }

        return 'none';
    };

    // The tasks extension is off in these tests, so the cancel admission is refused for that reason: the job reached it and tries again.
    expect($code())->toBe('extension.disabled');

    Task::topLevel()->findOrFail($proof->target_id)->update(['status' => TaskGroupStatus::Cancelled]);

    expect($code())->toBe('none');
});

it('still admits the cancel of a group that is marked cancelled while its VM is not destroyed, and stops once the VM is destroyed', function (): void {
    $proof = proofRunning();
    $proof->update(['status' => OperationStatus::Cancelled]);
    Task::topLevel()->findOrFail($proof->target_id)->update(['status' => TaskGroupStatus::Cancelled]);
    $vm = TaskVm::query()->create(['group_id' => $proof->target_id, 'host_node_id' => $proof->node_id, 'provider' => TaskVmProviderKind::Incus, 'name' => 'tvm-1', 'wireguard_ip' => '10.44.0.150',
        'state' => TaskVmState::Provisioning, 'size' => TaskVmSize::Normal]);
    $code = static function () use ($proof): string {
        try {
            app(ProofGroup::class)->cancel($proof);
        } catch (ResourceOperationException $exception) {
            return $exception->errorCode;
        }

        return 'none';
    };

    // The cancelled run left its VM in provisioning and its slot taken: the cancel must reach its admission (refused here only because the tasks extension is off).
    expect($code())->toBe('extension.disabled');

    $vm->update(['state' => TaskVmState::Destroyed]);

    expect($code())->toBe('none');
});

it('runs the cancel of the group in a job', function (): void {
    $proof = proofRunning();
    Task::topLevel()->findOrFail($proof->target_id)->update(['status' => TaskGroupStatus::Cancelled]);

    (new CancelProofGroup($proof->id))->handle(app(ProofGroup::class));

    expect($proof->refresh()->status)->toBe(OperationStatus::Running);
});

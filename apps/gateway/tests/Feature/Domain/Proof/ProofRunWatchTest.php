<?php

declare(strict_types=1);

use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofKeptVms;
use App\Domain\Proof\ProofRunSettlement;
use App\Domain\Proof\ProofRunWatch;
use App\Domain\Tasks\TaskCancelIntent;
use App\Domain\Tasks\TaskCancelProjection;
use App\Domain\Tasks\TaskGroupEnding;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmCapacity;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmRuntime;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Jobs\Proof\CancelProofGroup;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

const WATCH_SHA = 'a236a73038fe7ef64b124c216d0a22e0fdfbdfb2';

function watchNode(): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'swamp', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.9', 'wireguard_ip' => '10.44.0.9', 'user' => 'orbit']));
}

/** Sets created_at and updated_at without touching the model, which would move updated_at to now. */
function watchAged(Operation $operation, int $createdSecondsAgo, ?int $updatedSecondsAgo = null): Operation
{
    Operation::query()->whereKey($operation->id)->update(['created_at' => now()->subSeconds($createdSecondsAgo), 'updated_at' => now()->subSeconds($updatedSecondsAgo ?? $createdSecondsAgo)]);

    return $operation->refresh();
}

/** A proof.run as the admission left it: running, with the run list still empty. */
function watchProof(Node $node, int $ageSeconds, OperationStatus $status = OperationStatus::Running): Operation
{
    $proof = Operation::query()->create(['kind' => 'proof.run', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'input' => ['commit' => WATCH_SHA, 'all' => false, 'cases' => [], 'uncovered' => [['path' => 'a', 'reason' => 'b']], 'runs' => []],
        'state' => [], 'status' => $status, 'sequence' => 2, 'attempts' => 0]);

    return watchAged($proof, $ageSeconds);
}

/** A run of one command that names its proof. */
function watchRun(Operation $proof, string $command, OperationStatus $status, ?string $error = null, bool $started = false, int $ageSeconds = 900): Operation
{
    $run = Operation::query()->create(['kind' => 'proof.command', 'target_type' => 'task_group', 'target_id' => 1, 'node_id' => $proof->node_id,
        'input' => ['proof_operation_id' => $proof->id, 'commit' => WATCH_SHA, 'command' => $command, 'file' => 'swamp/proof/'.str_replace(':', '/', $command).'.cases.yaml'],
        'state' => $started ? ['swamp_run_id' => 'run-1'] : [], 'status' => $status, 'error_code' => $error, 'sequence' => 1, 'attempts' => 0]);

    return watchAged($run, $ageSeconds);
}

it('ends a proof that has no run once its request is gone, with a clear code, instead of leaving runs empty', function (): void {
    $proof = watchProof(watchNode(), ProofRunWatch::AdmissionSeconds + 30);

    expect(app(ProofRunWatch::class)->sweep())->toBe(1);

    $proof->refresh();
    expect($proof->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('proof.admission_interrupted')->and($proof->state['native_failure']['detail'])->toBe(ProofRunWatch::Lost)
        ->and($proof->result)->toBe(['commit' => WATCH_SHA, 'outcome' => 'failed', 'cases' => [], 'uncovered' => [['path' => 'a', 'reason' => 'b']], 'receipt_url' => null]);
});

it('ends a waiting proof that is past its admission budget, and leaves a younger one waiting', function (): void {
    $node = watchNode();
    $lost = watchProof($node, ProofRunWatch::AdmissionSeconds + 30, OperationStatus::Waiting);
    $young = watchProof($node, 600, OperationStatus::Waiting);

    expect(app(ProofRunWatch::class)->sweep())->toBe(1);

    expect($lost->refresh()->status)->toBe(OperationStatus::Failed)->and($lost->error_code)->toBe('proof.admission_interrupted')
        ->and($young->refresh()->status)->toBe(OperationStatus::Waiting);
});

it('leaves a proof alone while its request may still be admitting', function (): void {
    $proof = watchProof(watchNode(), 60);

    expect(app(ProofRunWatch::class)->sweep())->toBe(0)->and($proof->refresh()->status)->toBe(OperationStatus::Running);
});

it('leaves a proof alone while its runs are still being handed to Swamp one after another', function (): void {
    Queue::fake();
    $proof = watchProof(watchNode(), 600);
    watchRun($proof, 'firewall:allow', OperationStatus::Running, started: true, ageSeconds: 600);
    $slow = watchRun($proof, 'firewall:list', OperationStatus::Running, ageSeconds: 600);
    watchAged($slow, 600, 60);

    expect(app(ProofRunWatch::class)->sweep())->toBe(0);

    Queue::assertNotPushed(CancelProofGroup::class);
    expect($proof->refresh()->status)->toBe(OperationStatus::Running)->and($slow->refresh()->status)->toBe(OperationStatus::Running);
});

it('ends only the runs that were never handed to Swamp when the request is gone, and keeps the running one', function (): void {
    Queue::fake();
    $proof = watchProof(watchNode(), ProofRunWatch::AdmissionSeconds + 1);
    $running = watchRun($proof, 'firewall:allow', OperationStatus::Running, started: true);
    $lost = watchRun($proof, 'firewall:list', OperationStatus::Running);

    app(ProofRunWatch::class)->sweep();

    expect($lost->refresh()->status)->toBe(OperationStatus::Failed)->and($lost->error_code)->toBe('proof.admission_interrupted')
        ->and($running->refresh()->status)->toBe(OperationStatus::Running)->and($proof->refresh()->status)->toBe(OperationStatus::Running);
    Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $lost->id && $job->trigger === 'proof-run');
});

it('closes a proof from its runs although the run list in the proof is empty, and names the runs that were never started', function (): void {
    Queue::fake();
    $proof = watchProof(watchNode(), ProofRunWatch::AdmissionSeconds + 1);
    watchRun($proof, 'firewall:allow', OperationStatus::Running);
    watchRun($proof, 'firewall:list', OperationStatus::Running);

    expect(app(ProofRunWatch::class)->sweep())->toBe(1);

    $proof->refresh();
    expect($proof->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('proof.admission_interrupted')
        ->and(array_column($proof->result['cases'], 'verdict'))->toBe(['unrun', 'unrun'])
        ->and(array_column($proof->result['cases'], 'command'))->toBe(['firewall:allow', 'firewall:list']);
});

it('counts only the runs that name the proof, never those of the same commit or command', function (): void {
    $node = watchNode();
    $proof = watchProof($node, ProofRunWatch::AdmissionSeconds + 1);
    $other = watchProof($node, ProofRunWatch::AdmissionSeconds + 1);
    watchRun($other, 'firewall:allow', OperationStatus::Running, started: true);

    expect(ProofRunSettlement::children($proof->id))->toHaveCount(0)->and(ProofRunSettlement::children($other->id))->toHaveCount(1);

    app(ProofRunWatch::class)->sweep();

    // The other proof's running run keeps that proof open. This one has no run of its own and ends.
    expect($proof->refresh()->error_code)->toBe('proof.admission_interrupted')->and($other->refresh()->status)->toBe(OperationStatus::Running);
});

it('does not shorten the admission limit of a run: a slow hand-over to Swamp is not an expiry', function (): void {
    $proof = watchProof(watchNode(), 10);
    $unstarted = watchRun($proof, 'firewall:allow', OperationStatus::Running);

    expect(OperationRunBudgets::seconds($unstarted))->toBe(600);
});

it('leaves a run alone that Swamp may have accepted: its start is uncertain, and the reconcile pass finds its run by tag', function (): void {
    Queue::fake();
    $proof = watchProof(watchNode(), ProofRunWatch::AdmissionSeconds + 1);
    $uncertain = watchRun($proof, 'firewall:allow', OperationStatus::Running, 'swamp.start_uncertain');

    expect(app(ProofRunWatch::class)->sweep())->toBe(0);

    Queue::assertNotPushed(CancelProofGroup::class);
    expect($uncertain->refresh()->status)->toBe(OperationStatus::Running)->and($proof->refresh()->status)->toBe(OperationStatus::Running);
});

/**
 * A failed run that kept its VM (`proof:run --keep-failed`): its group is still running, and the run records the keep.
 *
 * @return array{0: Operation, 1: Task}
 */
function watchKept(Operation $proof, string $until, ?string $asked = null, TaskGroupStatus $status = TaskGroupStatus::Running, string $command = 'firewall:allow'): array
{
    $group = Task::query()->create(['title' => 'Proof '.$command, 'brief' => 'Prove it', 'status' => $status]);
    $run = watchRun($proof, $command, OperationStatus::Failed, 'proof.cases_failed', started: true);
    $run->forceFill(['target_id' => $group->id, 'input' => [...$run->input, 'group_id' => $group->id, 'keep_failed' => true],
        'result' => ['kept' => ['vm' => 'tvm-600', 'group_id' => $group->id, 'keep_until' => $until, 'cancel_requested_at' => $asked]]])->save();

    return [$run, $group];
}

describe('the keep of a failed run', function (): void {
    beforeEach(fn () => Carbon::setTestNow('2026-11-07 10:00:00'));
    afterEach(fn () => Carbon::setTestNow());

    it('leaves the VM and the group alone until the 60 minutes are up', function (): void {
        Queue::fake();
        $proof = watchProof(watchNode(), ProofRunWatch::AdmissionSeconds + 1);
        watchKept($proof, '2026-11-07T10:00:01Z');

        app(ProofRunWatch::class)->sweep();

        Queue::assertNotPushed(CancelProofGroup::class);
    });

    it('cancels the group of the run when the time is up, with the trigger of the proof, and asks again only after a few minutes', function (): void {
        Queue::fake();
        $proof = watchProof(watchNode(), ProofRunWatch::AdmissionSeconds + 1);
        [$run] = watchKept($proof, '2026-11-07T10:00:00Z');
        $sweep = static fn () => app(ProofRunWatch::class)->sweep();

        $sweep();
        $sweep();
        Queue::assertPushed(CancelProofGroup::class, 1);
        Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $run->id && $job->trigger === 'proof-run');
        expect($run->refresh()->result['kept']['cancel_requested_at'])->toBe('2026-11-07T10:00:00Z');

        Carbon::setTestNow('2026-11-07 10:06:00');
        $sweep();

        Queue::assertPushed(CancelProofGroup::class, 2);
    });

    it('lets go of a keep whose group and VM are gone', function (): void {
        Queue::fake();
        $proof = watchProof(watchNode(), ProofRunWatch::AdmissionSeconds + 1);
        watchKept($proof, '2026-11-07T09:00:00Z', status: TaskGroupStatus::Cancelled);

        app(ProofRunWatch::class)->sweep();

        Queue::assertNotPushed(CancelProofGroup::class);
    });

    it('stops asking a day after the time is up, and a run whose cancel cannot be admitted does not hold the others', function (): void {
        Queue::fake();
        $node = watchNode();
        $proof = watchProof($node, ProofRunWatch::AdmissionSeconds + 1);
        [$old] = watchKept($proof, '2026-11-06T09:00:00Z');
        [$broken] = watchKept($proof, '2026-11-07T09:00:00Z', command: 'firewall:deny');
        [$fine] = watchKept($proof, '2026-11-07T09:30:00Z', command: 'firewall:list');
        $armed = true;
        Operation::saving(static function (Operation $operation) use (&$armed, $broken): void {
            if ($armed && $operation->id === $broken->id) {
                throw new RuntimeException('The write failed.');
            }
        });

        try {
            app(ProofRunWatch::class)->sweep();
        } finally {
            $armed = false;
        }

        Queue::assertPushed(CancelProofGroup::class, 1);
        Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $fine->id);
        expect($old->refresh()->result['kept']['cancel_requested_at'])->toBeNull();
    });

    it('says that a run keeps its VM only until the time is up', function (): void {
        $proof = watchProof(watchNode(), ProofRunWatch::AdmissionSeconds + 1);
        [$open] = watchKept($proof, '2026-11-07T10:00:01Z');
        [$over] = watchKept($proof, '2026-11-07T10:00:00Z', command: 'firewall:deny');
        $plain = watchRun($proof, 'firewall:list', OperationStatus::Failed, 'proof.cases_failed');

        expect(ProofRunWatch::keeps($open))->toBeTrue()->and(ProofRunWatch::keeps($over))->toBeFalse()->and(ProofRunWatch::keeps($plain))->toBeFalse();
    });
    it('cancels only the group of the run that kept its VM, never one of another proof for the same commit and command', function (): void {
        Queue::fake();
        $node = watchNode();
        $mine = watchProof($node, ProofRunWatch::AdmissionSeconds + 1);
        $other = watchProof($node, ProofRunWatch::AdmissionSeconds + 1);
        [$kept] = watchKept($mine, '2026-11-07T09:00:00Z');
        // The same commit and command, in a proof that was started without the flag: its failed run has no keep, and its group is running.
        $group = Task::query()->create(['title' => 'Proof firewall:allow', 'brief' => 'Prove it', 'status' => TaskGroupStatus::Running]);
        $stranger = watchRun($other, 'firewall:allow', OperationStatus::Failed, 'proof.cases_failed', started: true);
        $stranger->forceFill(['target_id' => $group->id, 'result' => ['cases' => []]])->save();

        app(ProofRunWatch::class)->sweep();

        Queue::assertPushed(CancelProofGroup::class, 1);
        Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $kept->id);
        expect($group->refresh()->status)->toBe(TaskGroupStatus::Running);
    });

    it('expires a keep although the watch of the proofs fails, so nobody has to come back', function (): void {
        Queue::fake();
        $node = watchNode();
        // A proof with no run: the watch ends it, and that write fails.
        watchProof($node, ProofRunWatch::AdmissionSeconds + 1);
        [$run] = watchKept(watchProof($node, 60), '2026-11-07T09:00:00Z');
        $armed = true;
        Operation::saving(static function (Operation $operation) use (&$armed): void {
            if ($armed && $operation->kind === 'proof.run') {
                throw new RuntimeException('The write failed.');
            }
        });

        try {
            expect(fn () => app(ProofRunWatch::class)->sweep())->toThrow(RuntimeException::class);
        } finally {
            $armed = false;
        }

        Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $run->id);
    });

    it('counts a kept VM against the capacity of its host until the group is cancelled', function (): void {
        $node = watchNode();
        $proof = watchProof($node, ProofRunWatch::AdmissionSeconds + 1);
        [, $group] = watchKept($proof, '2026-11-07T11:00:00Z');
        TaskVm::query()->create(['group_id' => $group->id, 'host_node_id' => $node->id, 'provider' => TaskVmProviderKind::Incus, 'name' => 'tvm-600', 'wireguard_ip' => '10.44.0.150',
            'state' => TaskVmState::Ready, 'size' => TaskVmSize::Normal]);

        expect(TaskVm::query()->live()->where('provider', TaskVmProviderKind::Incus)->count())->toBe(1);
    });

    it('counts a kept VM that failed to provision, which holds the CPU and the memory of its host, and counts every failed VM until deletion', function (): void {
        $node = watchNode();
        $proof = watchProof($node, ProofRunWatch::AdmissionSeconds + 1);
        [, $group] = watchKept($proof, '2026-11-07T11:00:00Z');
        $kept = TaskVm::query()->create(['group_id' => $group->id, 'host_node_id' => $node->id, 'provider' => TaskVmProviderKind::Incus, 'name' => 'tvm-853', 'wireguard_ip' => '10.44.0.151',
            'state' => TaskVmState::Failed, 'capacity_pool' => 'ci', 'size' => TaskVmSize::Normal]);
        $other = Task::query()->create(['title' => 'Another group', 'brief' => 'Brief', 'status' => TaskGroupStatus::Failed]);
        TaskVm::query()->create(['group_id' => $other->id, 'host_node_id' => $node->id, 'provider' => TaskVmProviderKind::Incus, 'name' => 'tvm-854', 'wireguard_ip' => '10.44.0.152',
            'state' => TaskVmState::Failed, 'capacity_pool' => 'ci', 'size' => TaskVmSize::Normal]);
        $host = new TaskVmHost($node->id, 'orbit-tasks', 'orbittask0', '10.252.7.0/24', 6, 4, '8GiB', '20GiB', ciPlaces: 6);
        $free = static fn (): int => app(TaskVmCapacity::class)->freeSlots([[$host, $node]], proof: true, runtime: TaskVmRuntime::Vm)[$node->id];

        expect(TaskVm::query()->live()->where('provider', TaskVmProviderKind::Incus)->pluck('name')->all())->toBe(['tvm-853', 'tvm-854'])->and($free())->toBe(4);
    });

    it('frees the slot of a kept VM when the release of kept VMs cancels its group and the VM is destroyed', function (): void {
        Queue::fake();
        $node = watchNode();
        $proof = watchProof($node, ProofRunWatch::AdmissionSeconds + 1);
        [$run, $group] = watchKept($proof, '2026-11-07T11:00:00Z');
        $vm = TaskVm::query()->create(['group_id' => $group->id, 'host_node_id' => $node->id, 'provider' => TaskVmProviderKind::Incus, 'name' => 'tvm-853', 'wireguard_ip' => '10.44.0.151',
            'state' => TaskVmState::Failed, 'capacity_pool' => 'ci', 'size' => TaskVmSize::Normal]);
        $host = new TaskVmHost($node->id, 'orbit-tasks', 'orbittask0', '10.252.7.0/24', 6, 4, '8GiB', '20GiB', ciPlaces: 6);
        $free = static fn (): int => app(TaskVmCapacity::class)->freeSlots([[$host, $node]], proof: true, runtime: TaskVmRuntime::Vm)[$node->id];
        expect($free())->toBe(5);

        // Expiry asks for release; the completed deletion frees the CI place.
        expect(app(ProofKeptVms::class)->release($run->id))->toBeTrue();
        Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $run->id && $job->trigger === 'proof-run');
        $group->update(['status' => TaskGroupStatus::Cancelled]);
        $vm->update(['state' => TaskVmState::Destroyed]);

        expect($free())->toBe(6)->and(app(ProofKeptVms::class)->release($run->id))->toBeFalse();
    });
});

describe('the released marker of a kept VM', function (): void {
    beforeEach(fn () => Carbon::setTestNow('2026-11-07 10:00:00'));
    afterEach(fn () => Carbon::setTestNow());

    it('marks the kept VM of a group as released, with the time and the operation of the cancel, on the run and on the result of its proof', function (): void {
        $proof = watchProof(watchNode(), ProofRunWatch::AdmissionSeconds + 1);
        [$run, $group] = watchKept($proof, '2026-11-07T11:00:00Z');
        [$other] = watchKept($proof, '2026-11-07T11:00:00Z', command: 'firewall:list');
        $proof->forceFill(['status' => OperationStatus::Failed, 'result' => ['commit' => WATCH_SHA, 'kept' => [
            ['command' => 'firewall:allow', 'vm' => 'tvm-600', 'group_id' => $group->id, 'keep_until' => '2026-11-07T11:00:00Z'],
            ['command' => 'firewall:list', 'vm' => 'tvm-601', 'group_id' => $other->target_id, 'keep_until' => '2026-11-07T11:00:00Z']]]])->save();
        $cancel = Operation::query()->create(['kind' => 'tasks.cancel', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $run->node_id, 'input' => [], 'state' => [],
            'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 0]);

        app(ProofKeptVms::class)->released($cancel, $group->id);

        $mark = ['released_at' => '2026-11-07T10:00:00Z', 'released_by' => $cancel->id];
        expect($run->refresh()->result['kept'])->toMatchArray($mark)->and($other->refresh()->result['kept'])->not->toHaveKey('released_at')
            ->and($proof->refresh()->result['kept'][0])->toMatchArray($mark)->and($proof->result['kept'][1])->not->toHaveKey('released_at');
    });

    it('marks the release once, and leaves a group that kept nothing alone', function (): void {
        $proof = watchProof(watchNode(), ProofRunWatch::AdmissionSeconds + 1);
        [$run, $group] = watchKept($proof, '2026-11-07T11:00:00Z');
        $first = Operation::query()->create(['kind' => 'tasks.cancel', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $run->node_id, 'input' => [], 'state' => [],
            'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 0]);
        $second = $first->replicate();
        $second->save();

        app(ProofKeptVms::class)->released($first, $group->id);
        Carbon::setTestNow('2026-11-07 10:30:00');
        app(ProofKeptVms::class)->released($second, $group->id);
        app(ProofKeptVms::class)->released($first, $group->id + 999);

        expect($run->refresh()->result['kept'])->toMatchArray(['released_at' => '2026-11-07T10:00:00Z', 'released_by' => $first->id]);
    });

    it('marks the release when the cancel of the group completes, as the projection of tasks.cancel settles it', function (): void {
        $proof = watchProof(watchNode(), ProofRunWatch::AdmissionSeconds + 1);
        [$run, $group] = watchKept($proof, '2026-11-07T11:00:00Z');
        $cancel = Operation::query()->create(['kind' => 'tasks.cancel', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $run->node_id, 'state' => ['swamp_run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a'],
            'input' => ['instance_id' => 7], 'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 0]);
        // The cleanup that the cancel captured is not the subject here: the group ends, and the projection must then say that the kept VM is released.
        app()->instance(TaskGroupEnding::class, new class
        {
            public function settle(Task $group, array $captured, string $kind): void
            {
                $group->update(['status' => TaskGroupStatus::Cancelled]);
            }
        });
        app()->instance(TaskCancelIntent::class, new class
        {
            public function input(Operation $operation): array
            {
                return $operation->input;
            }
        });

        app(TaskCancelProjection::class)->apply($cancel, ['run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => 'succeeded', 'started_at' => now()->subMinute()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => ['group_id' => $group->id, 'instance_id' => 7, 'cleanup_complete' => true]]);

        expect($run->refresh()->result['kept'])->toMatchArray(['released_at' => '2026-11-07T10:00:00Z', 'released_by' => $cancel->id]);
    });
});

it('leaves an operator proof in task admission to its capacity budget instead of marking its child lost', function (): void {
    Queue::fake();
    $proof = watchProof(watchNode(), ProofRunWatch::AdmissionSeconds + 1);
    $waiting = watchRun($proof, 'firewall:allow', OperationStatus::Waiting, ageSeconds: 900);
    $waiting->update(['input' => [...$waiting->input, 'capacity_request' => ['commit' => WATCH_SHA]]]);
    watchAged($waiting, 900);

    expect(app(ProofRunWatch::class)->sweep())->toBe(0)
        ->and($proof->refresh()->status)->toBe(OperationStatus::Running)
        ->and($waiting->refresh()->status)->toBe(OperationStatus::Waiting);
    Queue::assertNotPushed(CancelProofGroup::class);
});

it('ends held children after a lost successor only when the proof is old and every child has been quiet', function (): void {
    $this->freezeTime();
    Queue::fake();
    $proof = watchProof(watchNode(), ProofRunWatch::AdmissionSeconds + 1);
    $answered = watchRun($proof, 'firewall:allow', OperationStatus::Failed, 'swamp.start_job_failed');
    $held = watchRun($proof, 'firewall:list', OperationStatus::Queued);
    $held->update(['state' => ['swamp_start' => ['key' => 'operation:'.$held->id, 'workflow' => 'operation-proof-run'],
        'proof_child_start' => ['not_before' => now()->subSeconds(900)->toISOString(), 'released_at' => null, 'advanced_at' => null]]]);
    watchAged($held, 900, 299);
    expect(app(ProofRunWatch::class)->sweep())->toBe(0);
    expect(OperationRunBudgets::deadline($held))->toBeNull();
    $this->travel(2)->seconds();

    expect(app(ProofRunWatch::class)->sweep())->toBe(1);
    expect($held->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($held->error_code)->toBe('proof.admission_interrupted');
    expect($proof->refresh()->status)->toBe(OperationStatus::Failed)
        ->and(array_column($proof->result['cases'], 'verdict'))->toBe(['unrun', 'unrun']);
    Queue::assertPushed(CancelProofGroup::class, fn ($job): bool => $job->operationId === $held->id);
});

it('keeps the existing query count for proofs without holds while repairing held proofs', function (int $age, int $expected): void {
    $this->freezeSecond();
    Queue::fake();
    $proof = watchProof(watchNode(), $age);
    watchRun($proof, 'firewall:allow', OperationStatus::Running, started: true);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    app(ProofRunWatch::class)->sweep();
    expect($queries)->toHaveCount($expected);

    $repair = watchProof(Node::query()->findOrFail($proof->node_id), 60);
    $answered = watchRun($repair, 'firewall:allow', OperationStatus::Failed);
    $held = watchRun($repair, 'firewall:list', OperationStatus::Queued);
    foreach ([$answered, $held] as $child) {
        $child->update(['state' => ['proof_child_start' => ['not_before' => null, 'released_at' => null, 'advanced_at' => null]]]);
    }
    app(ProofRunWatch::class)->sweep();
    Queue::assertPushed(StartAdmittedSwampRun::class, fn ($job): bool => $job->operationId === $held->id && $job->delay->equalTo(now()->addSeconds(20)));
})->with(['young' => [60, 2], 'old' => [ProofRunWatch::AdmissionSeconds + 1, 3]]);

/** The reviewer's lost queued start, still held even though its parent has ended. */
function watchStrandedHeld(Operation $proof, int $age = 90 * 86400): Operation
{
    $child = watchRun($proof, 'firewall:allow', OperationStatus::Queued);
    $child->update(['state' => ['swamp_start' => ['key' => 'operation:'.$child->id, 'workflow' => 'operation-proof-run'],
        'proof_child_start' => ['not_before' => now()->subSeconds(20)->toISOString(), 'released_at' => null, 'advanced_at' => null]]]);

    return watchAged($child, $age);
}

it('cancels months-old held children of terminal or missing parents on the first pass and queues normal cleanup once', function (string $status): void {
    Queue::fake();
    $proof = watchProof(watchNode(), 90 * 86400);
    $child = watchStrandedHeld($proof);
    if ($status === 'missing') {
        $proof->delete();
    } else {
        $proof->update(['status' => OperationStatus::from($status)]);
    }

    app(ProofRunWatch::class)->sweep();

    expect($child->refresh()->status)->toBe(OperationStatus::Cancelled)->and($child->cancel_requested_at)->not->toBeNull();
    Queue::assertPushed(CancelProofGroup::class, 1);
    Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $child->id && $job->trigger === 'proof-run');
    $before = $child->getAttributes();
    app(ProofRunWatch::class)->sweep();
    expect($child->refresh()->getAttributes())->toBe($before);
    Queue::assertPushed(CancelProofGroup::class, 1);
})->with(['cancelled', 'succeeded', 'failed', 'interrupted', 'missing']);

it('recovers stranded children without touching an open parent child, a released child or a bound child', function (string $case): void {
    Queue::fake();
    $node = watchNode();
    $ended = watchProof($node, 60);
    $stranded = watchStrandedHeld($ended, 60);
    $ended->update(['status' => OperationStatus::Cancelled]);
    $parent = $case === 'open' ? watchProof($node, 60) : $ended;
    $protected = watchStrandedHeld($parent, 60);
    if ($case === 'open') {
        $protected->update(['state' => [...($protected->state ?? []), 'proof_child_start' => ['not_before' => now()->addMinute()->toISOString(), 'released_at' => null]]]);
    } else {
        $parent->update(['status' => OperationStatus::Cancelled]);
        $protected->update(['state' => [...($protected->state ?? []),
            ...($case === 'bound' ? ['swamp_run_id' => 'bound-proof-child'] : []),
            'proof_child_start' => ['released_at' => $case === 'released' ? now()->toISOString() : null]]]);
    }
    $before = $protected->refresh()->getAttributes();
    app(ProofRunWatch::class)->sweep();
    expect($stranded->refresh()->status)->toBe(OperationStatus::Cancelled)
        ->and($protected->refresh()->getAttributes())->toBe($before);
    Queue::assertPushed(CancelProofGroup::class, 1);
})->with(['open', 'released', 'bound']);

it('keeps the two empty-fleet reads and folds stranded children into the existing watchdog selection', function (): void {
    Queue::fake();
    $node = watchNode();
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    app(ProofRunWatch::class)->sweep();
    expect($queries)->toHaveCount(2);
    $empty = count($queries);
    $proof = watchProof($node, 60);
    $child = watchStrandedHeld($proof, 60);
    $proof->update(['status' => OperationStatus::Cancelled]);
    app(ProofRunWatch::class)->sweep();
    expect($child->refresh()->status)->toBe(OperationStatus::Cancelled);
    fwrite(STDOUT, "\nHeld-child watchdog queries: ".json_encode(['empty_fleet' => $empty, 'additional_empty_reads' => 0], JSON_THROW_ON_ERROR)."\n");
});

<?php

declare(strict_types=1);

use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofRunContinuation;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Jobs\Proof\AdvanceProofRun;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

/** A running proof.run with two cases, on a group that carries it. */
function proofRunning(): Operation
{
    $group = Task::query()->create(['title' => 'Proof b3f1c0de0000', 'brief' => 'Prove it', 'status' => TaskGroupStatus::Running]);
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'swamp', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.9', 'wireguard_ip' => '10.44.0.9', 'user' => 'orbit']));

    return Operation::query()->create(['kind' => 'proof.run', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $node->id,
        'input' => ['commit' => str_repeat('a', 40), 'uncovered' => [], 'group_id' => $group->id, 'cases' => [
            ['program' => 'swamp/proof/a/cases/one.sh', 'command' => 'a:one'], ['program' => 'swamp/proof/b/cases/two.sh', 'command' => 'b:two']]],
        'state' => [], 'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 0]);
}

function proofStep(Operation $proof, string $kind, array $fields = []): Operation
{
    $step = Operation::query()->create(['kind' => $kind, 'target_type' => 'task_group', 'target_id' => $proof->target_id, 'node_id' => $proof->node_id,
        'input' => [], 'state' => [], 'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 0, ...$fields]);
    try {
        app(ProofRunContinuation::class)->advance($step);
    } catch (ResourceOperationException) {
        // The tasks extension is off in these tests, so the cancel is refused; the verdict is recorded before it.
    }

    return $step;
}

it('reads the verdict of each case from the last line of the case run, then asks for the group to be cancelled', function (): void {
    $proof = proofRunning();
    $line = json_encode(['status' => 'failed', 'summary' => [['p', 12.4], ['f', 3.1]], 'exit' => 1, 'cases_ran' => true, 'generation' => 4, 'evidence' => '/home/orbit/evidence/4']);

    proofStep($proof, 'tasks.script', ['result' => ['outcome' => 'failed', 'output' => "noise\n".$line."\n"]]);

    expect($proof->refresh()->state['verdict'])->toBe(['ran' => true, 'evidence' => '/home/orbit/evidence/4', 'cases' => [
        ['program' => 'swamp/proof/a/cases/one.sh', 'command' => 'a:one', 'verdict' => 'passed', 'seconds' => 12.4],
        ['program' => 'swamp/proof/b/cases/two.sh', 'command' => 'b:two', 'verdict' => 'failed', 'seconds' => 3.1]]]);
});

it('leaves every case unrun when the case run printed no usable line', function (): void {
    $proof = proofRunning();

    proofStep($proof, 'tasks.script', ['result' => ['outcome' => 'failed', 'output' => 'Traceback']]);

    expect(array_column($proof->refresh()->state['verdict']['cases'], 'verdict'))->toBe(['unrun', 'unrun'])->and($proof->state['verdict']['ran'])->toBeFalse();
});

it('closes the proof as passed once the group is cancelled and every case passed', function (): void {
    $proof = proofRunning();
    $proof->update(['state' => ['verdict' => ['ran' => true, 'evidence' => '/e', 'cases' => [
        ['program' => 'swamp/proof/a/cases/one.sh', 'command' => 'a:one', 'verdict' => 'passed', 'seconds' => 1],
        ['program' => 'swamp/proof/b/cases/two.sh', 'command' => 'b:two', 'verdict' => 'passed', 'seconds' => 2]]]]]);

    proofStep($proof, 'tasks.cancel');

    expect($proof->refresh()->status)->toBe(OperationStatus::Succeeded)->and($proof->error_code)->toBeNull()
        ->and($proof->result['outcome'])->toBe('passed')->and($proof->result['evidence'])->toBe('/e');
});

it('closes the proof as failed with every case unrun when the group never ran the cases', function (): void {
    $proof = proofRunning();

    proofStep($proof, 'tasks.cancel');

    expect($proof->refresh()->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('proof.run_failed')
        ->and(array_column($proof->result['cases'], 'verdict'))->toBe(['unrun', 'unrun']);
});

it('closes the proof as cases_failed when a case ran and failed', function (): void {
    $proof = proofRunning();
    $proof->update(['state' => ['verdict' => ['ran' => true, 'evidence' => null, 'cases' => [
        ['program' => 'swamp/proof/a/cases/one.sh', 'command' => 'a:one', 'verdict' => 'failed', 'seconds' => 1],
        ['program' => 'swamp/proof/b/cases/two.sh', 'command' => 'b:two', 'verdict' => 'unrun', 'seconds' => 0]]]]]);

    proofStep($proof, 'tasks.cancel');

    expect($proof->refresh()->error_code)->toBe('proof.cases_failed');
});

it('does nothing for a group without a proof', function (): void {
    Queue::fake();
    $proof = proofRunning();
    $other = Task::query()->create(['title' => 'Feature', 'brief' => 'Do it', 'status' => TaskGroupStatus::Running]);
    $step = Operation::query()->create(['kind' => 'tasks.cancel', 'target_type' => 'task_group', 'target_id' => $other->id, 'node_id' => $proof->node_id,
        'input' => [], 'state' => [], 'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 0]);

    app(ProofRunContinuation::class)->apply($step);
    app(ProofRunContinuation::class)->advance($step);

    Queue::assertNothingPushed();
    expect($proof->refresh()->status)->toBe(OperationStatus::Running);
});

it('queues one job when a task operation of a running proof ends', function (): void {
    Queue::fake();
    $proof = proofRunning();
    $step = Operation::query()->create(['kind' => 'tasks.script', 'target_type' => 'task_group', 'target_id' => $proof->target_id, 'node_id' => $proof->node_id,
        'input' => [], 'state' => [], 'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 0]);

    app(ProofRunContinuation::class)->apply($step);

    Queue::assertPushed(AdvanceProofRun::class, static fn (AdvanceProofRun $job): bool => $job->operationId === $step->id);
});

it('closes the proof failed when the job gives up', function (): void {
    $proof = proofRunning();
    $step = Operation::query()->create(['kind' => 'tasks.script', 'target_type' => 'task_group', 'target_id' => $proof->target_id, 'node_id' => $proof->node_id,
        'input' => [], 'state' => [], 'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 0]);

    (new AdvanceProofRun($step->id))->failed(new RuntimeException('refused'));

    expect($proof->refresh()->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('proof.run_failed');
});

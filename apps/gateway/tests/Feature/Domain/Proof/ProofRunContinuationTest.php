<?php

declare(strict_types=1);

use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofRunContinuation;
use App\Domain\Proof\ProofVerifyProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScriptProjection;
use App\Domain\Tasks\TaskStatus;
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
    $group->tasks()->create(['title' => 'Run the proof cases', 'brief' => 'Prove it', 'status' => TaskStatus::Running, 'position' => 1]);
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'swamp', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.9', 'wireguard_ip' => '10.44.0.9', 'user' => 'orbit']));

    return Operation::query()->create(['kind' => 'proof.run', 'target_type' => 'proof', 'target_id' => $group->id, 'node_id' => $node->id,
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

/** A finished proof.verify of the group, as the projection records it. */
function proofVerified(Operation $proof, string $command, string $file, array $lines, string $status = 'succeeded'): Operation
{
    return proofStep($proof, 'proof.verify', ['input' => ['command' => $command, 'file' => $file, 'checkout_path' => '/home/orbit/work'], 'result' => ['lines' => $lines],
        'status' => $status === 'succeeded' ? OperationStatus::Succeeded : OperationStatus::Failed]);
}

it('records the rows of the case lines of a verify, with the cases file and the case id, then moves to the next command', function (): void {
    $proof = proofRunning();

    proofVerified($proof, 'a:one', 'swamp/proof/a/one.cases.yaml', [
        ['id' => 'first', 'verdict' => 'passed', 'seconds' => 12.4],
        ['id' => 'second', 'verdict' => 'failed', 'seconds' => 3.1, 'cause' => 'assertion', 'failed' => ['expect[0] probe: x']]]);

    $state = $proof->refresh()->state;
    expect($state['stage'])->toBe(1)->and($state['evidence'])->toBe('/home/orbit/work/.orbit-artifacts/'.$proof->target_id.'/verify')
        ->and($state['rows'])->toBe([
            ['program' => 'swamp/proof/a/one.cases.yaml#first', 'command' => 'a:one', 'case' => 'first', 'verdict' => 'passed', 'seconds' => 12.4],
            ['program' => 'swamp/proof/a/one.cases.yaml#second', 'command' => 'a:one', 'case' => 'second', 'verdict' => 'failed', 'seconds' => 3.1, 'cause' => 'assertion', 'failed' => ['expect[0] probe: x']]]);
});

it('derives the cases file of a command from its family and name', function (): void {
    $proof = proofRunning();
    $proof->update(['input' => [...$proof->input, 'cases' => [['program' => 'swamp/proof/node/cases/role-add-x.sh', 'command' => 'node:role:add']]]]);

    proofStep($proof, 'tasks.cancel');

    expect($proof->refresh()->result['cases'])->toBe([['program' => 'swamp/proof/node/role-add.cases.yaml', 'command' => 'node:role:add', 'verdict' => 'unrun', 'seconds' => 0]]);
});

it('leaves the cases of a verify that printed no line unrun', function (): void {
    $proof = proofRunning();

    proofVerified($proof, 'a:one', 'swamp/proof/a/one.cases.yaml', [], 'failed');
    proofStep($proof, 'tasks.cancel');

    expect($proof->refresh()->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('proof.run_failed')
        ->and(array_column($proof->result['cases'], 'verdict'))->toBe(['unrun', 'unrun']);
});

it('closes the proof as passed once the group is cancelled and every case passed', function (): void {
    $proof = proofRunning();
    $proof->update(['state' => ['stage' => 2, 'evidence' => '/e', 'rows' => [
        ['program' => 'swamp/proof/a/one.cases.yaml#x', 'command' => 'a:one', 'case' => 'x', 'verdict' => 'passed', 'seconds' => 1],
        ['program' => 'swamp/proof/b/two.cases.yaml#y', 'command' => 'b:two', 'case' => 'y', 'verdict' => 'passed', 'seconds' => 2]]]]);

    proofStep($proof, 'tasks.cancel');

    expect($proof->refresh()->status)->toBe(OperationStatus::Succeeded)->and($proof->error_code)->toBeNull()
        ->and($proof->result['outcome'])->toBe('passed')->and($proof->result['evidence'])->toBe('/e')->and(count($proof->result['cases']))->toBe(2);
});

it('closes the proof as failed with every case unrun when no verify ran', function (): void {
    $proof = proofRunning();

    proofStep($proof, 'tasks.cancel');

    expect($proof->refresh()->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('proof.run_failed')
        ->and(array_column($proof->result['cases'], 'verdict'))->toBe(['unrun', 'unrun'])->and($proof->result['evidence'])->toBeNull();
});

it('closes the proof as cases_failed when a case ran and failed, and a later command is unrun', function (): void {
    $proof = proofRunning();
    $proof->update(['state' => ['stage' => 1, 'rows' => [
        ['program' => 'swamp/proof/a/one.cases.yaml#x', 'command' => 'a:one', 'case' => 'x', 'verdict' => 'failed', 'seconds' => 1]]]]);

    proofStep($proof, 'tasks.cancel');

    expect($proof->refresh()->error_code)->toBe('proof.cases_failed')->and(array_column($proof->result['cases'], 'verdict'))->toBe(['failed', 'unrun'])
        ->and($proof->result['cases'][1])->toBe(['program' => 'swamp/proof/b/two.cases.yaml', 'command' => 'b:two', 'verdict' => 'unrun', 'seconds' => 0]);
});

it('does not continue to the next command once cancel is requested', function (): void {
    $proof = proofRunning();
    $proof->update(['cancel_requested_at' => now()]);

    proofVerified($proof, 'a:one', 'swamp/proof/a/one.cases.yaml', [['id' => 'x', 'verdict' => 'passed', 'seconds' => 1]]);

    expect($proof->refresh()->state['stage'])->toBe(1)->and(count($proof->state['rows']))->toBe(1);
});

it('verifies after a reset that passed and cancels after a reset that failed', function (): void {
    $proof = proofRunning();
    $step = static fn (string $outcome): Operation => Operation::query()->create(['kind' => 'tasks.script', 'target_type' => 'task_group', 'target_id' => $proof->target_id,
        'node_id' => $proof->node_id, 'input' => [], 'state' => [], 'status' => OperationStatus::Succeeded, 'result' => ['outcome' => $outcome], 'sequence' => 1, 'attempts' => 0]);
    $code = static function (Operation $operation): string {
        try {
            app(ProofRunContinuation::class)->advance($operation);
        } catch (ResourceOperationException $exception) {
            return $exception->errorCode;
        }

        return 'none';
    };

    // The group has no task VM workspace in this test, so the verify is refused for that reason; the cancel is refused because the extension is off.
    expect($code($step('passed')))->toBe('tasks.no_workspace')->and($code($step('failed')))->toBe('extension.disabled');
});

it('queues the next step when the Gateway times out a script or a verify of a running proof', function (): void {
    Queue::fake();
    $proof = proofRunning();
    foreach ([[new TaskScriptProjection, 'tasks.script'], [new ProofVerifyProjection, 'proof.verify']] as [$projection, $kind]) {
        $step = Operation::query()->create(['kind' => $kind, 'target_type' => 'task_group', 'target_id' => $proof->target_id, 'node_id' => $proof->node_id,
            'input' => [], 'state' => [], 'status' => OperationStatus::Failed, 'error_code' => 'operation.timed_out', 'sequence' => 1, 'attempts' => 0]);

        $projection->timedOut($step);

        Queue::assertPushed(AdvanceProofRun::class, static fn (AdvanceProofRun $job): bool => $job->operationId === $step->id);
    }
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

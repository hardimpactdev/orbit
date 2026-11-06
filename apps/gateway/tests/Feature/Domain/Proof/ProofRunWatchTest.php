<?php

declare(strict_types=1);

use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofRunSettlement;
use App\Domain\Proof\ProofRunWatch;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Jobs\Proof\CancelProofGroup;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
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
function watchProof(Node $node, int $ageSeconds): Operation
{
    $proof = Operation::query()->create(['kind' => 'proof.run', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'input' => ['commit' => WATCH_SHA, 'all' => false, 'cases' => [], 'uncovered' => [['path' => 'a', 'reason' => 'b']], 'runs' => []],
        'state' => [], 'status' => OperationStatus::Running, 'sequence' => 2, 'attempts' => 0]);

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

it('leaves a proof alone while its request may still be admitting', function (): void {
    $proof = watchProof(watchNode(), 60);

    expect(app(ProofRunWatch::class)->sweep())->toBe(0)->and($proof->refresh()->status)->toBe(OperationStatus::Running);
});

it('frees the admission lock that the dead request of the proof held, and only that lock', function (): void {
    $proof = watchProof(watchNode(), 400);
    // A killed request leaves the lock with its owner until the 600 s limit.
    expect(Cache::lock(ModelKeyIssuer::Lock, 600, ProofRunWatch::lockOwner($proof->id))->get())->toBeTrue();

    app(ProofRunWatch::class)->sweep();

    $next = Cache::lock(ModelKeyIssuer::Lock, 600);
    expect($next->get())->toBeTrue();
    $next->release();
});

it('keeps an admission lock that another admission or a router teardown holds', function (): void {
    $proof = watchProof(watchNode(), 400);
    $other = Cache::lock(ModelKeyIssuer::Lock, 600, 'router-teardown');
    expect($other->get())->toBeTrue();

    app(ProofRunWatch::class)->sweep();

    expect($proof->refresh()->error_code)->toBe('proof.admission_interrupted')->and(Cache::lock(ModelKeyIssuer::Lock, 600)->get())->toBeFalse();
    $other->release();
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
    $proof = watchProof(watchNode(), 900);
    $running = watchRun($proof, 'firewall:allow', OperationStatus::Running, started: true);
    $lost = watchRun($proof, 'firewall:list', OperationStatus::Running);

    app(ProofRunWatch::class)->sweep();

    expect($lost->refresh()->status)->toBe(OperationStatus::Failed)->and($lost->error_code)->toBe('proof.admission_interrupted')
        ->and($running->refresh()->status)->toBe(OperationStatus::Running)->and($proof->refresh()->status)->toBe(OperationStatus::Running);
    Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $lost->id && $job->trigger === 'proof-run');
});

it('closes a proof from its runs although the run list in the proof is empty, and names the runs that were never started', function (): void {
    Queue::fake();
    $proof = watchProof(watchNode(), 900);
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
    $proof = watchProof($node, 900);
    $other = watchProof($node, 900);
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
    $proof = watchProof(watchNode(), 900);
    $uncertain = watchRun($proof, 'firewall:allow', OperationStatus::Running, 'swamp.start_uncertain');

    expect(app(ProofRunWatch::class)->sweep())->toBe(0);

    Queue::assertNotPushed(CancelProofGroup::class);
    expect($uncertain->refresh()->status)->toBe(OperationStatus::Running)->and($proof->refresh()->status)->toBe(OperationStatus::Running);
});

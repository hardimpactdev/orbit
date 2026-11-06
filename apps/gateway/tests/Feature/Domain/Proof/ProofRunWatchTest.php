<?php

declare(strict_types=1);

use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofRunSettlement;
use App\Domain\Proof\ProofRunWatch;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

const WATCH_SHA = 'a236a73038fe7ef64b124c216d0a22e0fdfbdfb2';

function watchNode(): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'swamp', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.9', 'wireguard_ip' => '10.44.0.9', 'user' => 'orbit']));
}

/** A proof.run as the admission left it: running, with the run list still empty. */
function watchProof(Node $node, int $ageSeconds): Operation
{
    $proof = Operation::query()->create(['kind' => 'proof.run', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'input' => ['commit' => WATCH_SHA, 'all' => false, 'cases' => [], 'uncovered' => [['path' => 'a', 'reason' => 'b']], 'runs' => []],
        'state' => [], 'status' => OperationStatus::Running, 'sequence' => 2, 'attempts' => 0]);
    $proof->forceFill(['created_at' => now()->subSeconds($ageSeconds)])->save();

    return $proof;
}

/** A run of one command that names its proof. */
function watchRun(Operation $proof, string $command, OperationStatus $status, ?string $error = null, bool $started = false): Operation
{
    return Operation::query()->create(['kind' => 'proof.command', 'target_type' => 'task_group', 'target_id' => 1, 'node_id' => $proof->node_id,
        'input' => ['proof_operation_id' => $proof->id, 'commit' => WATCH_SHA, 'command' => $command, 'file' => 'swamp/proof/'.str_replace(':', '/', $command).'.cases.yaml'],
        'state' => $started ? ['swamp_run_id' => 'run-1'] : [], 'status' => $status, 'error_code' => $error, 'sequence' => 1, 'attempts' => 0]);
}

it('ends a proof that has no run 180 s after it started, with a clear code, instead of leaving runs empty', function (): void {
    $proof = watchProof(watchNode(), ProofRunWatch::AdmissionSeconds + 60);

    expect(app(ProofRunWatch::class)->sweep())->toBe(1);

    $proof->refresh();
    expect($proof->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('proof.admission_interrupted')
        ->and($proof->result)->toBe(['commit' => WATCH_SHA, 'outcome' => 'failed', 'cases' => [], 'uncovered' => [['path' => 'a', 'reason' => 'b']], 'receipt_url' => null]);
});

it('leaves a proof alone while its request may still be admitting', function (): void {
    $proof = watchProof(watchNode(), 60);

    expect(app(ProofRunWatch::class)->sweep())->toBe(0)->and($proof->refresh()->status)->toBe(OperationStatus::Running);
});

it('leaves a proof alone while any of its runs is still going', function (): void {
    $proof = watchProof(watchNode(), 900);
    watchRun($proof, 'firewall:allow', OperationStatus::Succeeded, started: true);
    watchRun($proof, 'firewall:list', OperationStatus::Running, started: true);

    expect(app(ProofRunWatch::class)->sweep())->toBe(0)->and($proof->refresh()->status)->toBe(OperationStatus::Running);
});

it('closes a proof from its runs although the run list in the proof is empty, and names an unstarted run that timed out', function (): void {
    $proof = watchProof(watchNode(), 900);
    watchRun($proof, 'firewall:allow', OperationStatus::Failed, 'operation.timed_out');
    watchRun($proof, 'firewall:list', OperationStatus::Failed, 'operation.timed_out');

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

it('gives an admitted run that no Swamp run started 180 s, not 600', function (): void {
    $proof = watchProof(watchNode(), 10);
    $unstarted = watchRun($proof, 'firewall:allow', OperationStatus::Running);
    $started = watchRun($proof, 'firewall:list', OperationStatus::Running, started: true);
    $script = Operation::query()->create(['kind' => 'tasks.script', 'target_type' => 'task_group', 'target_id' => 1, 'node_id' => $proof->node_id,
        'input' => [], 'state' => [], 'status' => OperationStatus::Running, 'sequence' => 1, 'attempts' => 0]);

    expect(OperationRunBudgets::seconds($unstarted))->toBe(180)->and(OperationRunBudgets::seconds($script))->toBe(600)
        ->and(OperationRunBudgets::seconds($started))->not->toBe(180);
});

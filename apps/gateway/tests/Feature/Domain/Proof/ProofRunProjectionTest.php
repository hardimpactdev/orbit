<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Proof\ProofRunProjection;
use App\Domain\Proof\ProofVm;
use App\Domain\Proof\ProofVmClaim;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/** A running proof run of two cases, with a VM claim that records each release. */
function runningProof(): array
{
    $swamp = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'swamp', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.9', 'wireguard_ip' => '10.44.0.9', 'user' => 'orbit']));
    $swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $claim = new class implements ProofVmClaim
    {
        /** @var list<string> */
        public array $released = [];

        public function claim(): ProofVm
        {
            return new ProofVm('tvm-17', 1, '/home/orbit/orbit');
        }

        public function release(string $name): void
        {
            $this->released[] = $name;
        }
    };
    app()->instance(ProofVmClaim::class, $claim);
    $operation = Operation::query()->create(['kind' => 'proof.run', 'target_type' => 'node', 'target_id' => $swamp->id, 'node_id' => $swamp->id,
        'status' => 'running', 'sequence' => 1, 'attempts' => 1, 'state' => ['swamp_run_id' => 'fixture-proof'], 'input' => [
            'commit' => str_repeat('b', 40), 'all' => false, 'everything_because' => null,
            'cases' => [['program' => 'swamp/proof/firewall/cases/allow-a.sh', 'command' => 'firewall:allow'], ['program' => 'swamp/proof/firewall/cases/deny-b.sh', 'command' => 'firewall:deny']],
            'uncovered' => [['path' => 'swamp/workflows/workflow-vault-sync.yaml', 'reason' => 'none: no case yet']],
            'vm' => ['name' => 'tvm-17', 'checkout_path' => '/home/orbit/orbit'], 'ssh_execution' => ['target' => ['host' => '10.231.0.5']],
        ]]);

    return [$operation, $claim];
}

/** @param  array<string,mixed>|null  $line */
function proofRun(string $status, ?array $line, string $endCheck = 'succeeded'): array
{
    return ['run_id' => 'fixture-proof', 'status' => $status, 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => [ProofRunProjection::EndCheck => ['status' => $endCheck], ...($line === null ? [] : [ProofRunProjection::Verify => ['status' => 'succeeded', 'stdout' => json_encode($line, JSON_THROW_ON_ERROR)."\n"]])],
            'summary' => ['failed_steps' => []]]];
}

it('records a passed run with a verdict for each case, and releases the VM', function (): void {
    [$operation, $claim] = runningProof();

    app(ProofRunProjection::class)->apply($operation, proofRun('succeeded', ['status' => 'succeeded', 'summary' => [['p', 12.4], ['p', 3]], 'exit' => 0, 'cases_ran' => true]));

    $operation->refresh();
    expect($operation->status->value)->toBe('succeeded')->and($operation->error_code)->toBeNull()
        ->and($operation->result)->toBe(['commit' => str_repeat('b', 40), 'outcome' => 'passed', 'cases' => [
            ['program' => 'swamp/proof/firewall/cases/allow-a.sh', 'command' => 'firewall:allow', 'verdict' => 'passed', 'seconds' => 12.4],
            ['program' => 'swamp/proof/firewall/cases/deny-b.sh', 'command' => 'firewall:deny', 'verdict' => 'passed', 'seconds' => 3],
        ], 'uncovered' => [['path' => 'swamp/workflows/workflow-vault-sync.yaml', 'reason' => 'none: no case yet']], 'vm' => 'tvm-17', 'receipt_url' => null])
        ->and($claim->released)->toBe(['tvm-17']);
});

it('fails with proof.cases_failed when a case failed, and keeps every verdict', function (): void {
    [$operation, $claim] = runningProof();

    app(ProofRunProjection::class)->apply($operation, proofRun('failed', ['status' => 'failed', 'summary' => [['p', 2], ['f', 1.5]], 'exit' => 1, 'cases_ran' => true], 'failed'));

    $operation->refresh();
    expect($operation->status->value)->toBe('failed')->and($operation->error_code)->toBe('proof.cases_failed')
        ->and(array_column($operation->result['cases'], 'verdict'))->toBe(['passed', 'failed'])->and($operation->result['outcome'])->toBe('failed')
        ->and($claim->released)->toBe(['tvm-17']);
});

it('fails with proof.run_failed and leaves every case unrun when the fleet never became ready', function (): void {
    [$operation] = runningProof();

    app(ProofRunProjection::class)->apply($operation, proofRun('failed', ['status' => 'failed', 'summary' => [['u', 0], ['u', 0]], 'exit' => 1, 'cases_ran' => false], 'failed'));

    $operation->refresh();
    expect($operation->error_code)->toBe('proof.run_failed')->and(array_column($operation->result['cases'], 'verdict'))->toBe(['unrun', 'unrun']);
});

it('leaves every case unrun when the run never printed its verdict line, as when the checkout is wrong', function (): void {
    [$operation, $claim] = runningProof();

    app(ProofRunProjection::class)->apply($operation, proofRun('failed', null, 'skipped'));

    $operation->refresh();
    expect($operation->error_code)->toBe('proof.run_failed')->and(array_column($operation->result['cases'], 'verdict'))->toBe(['unrun', 'unrun'])
        ->and($claim->released)->toBe(['tvm-17']);
});

it('ends a cancelled or interrupted run with every case it did not report unrun, and releases the VM', function (string $status): void {
    [$operation, $claim] = runningProof();

    app(ProofRunProjection::class)->apply($operation, proofRun($status, null, 'skipped'));

    expect($operation->refresh()->status->value)->not->toBe('succeeded')->and(array_unique(array_column($operation->result['cases'], 'verdict')))->toBe(['unrun'])
        ->and($claim->released)->toBe(['tvm-17']);
})->with(['cancelled', 'interrupted']);

it('refuses a success that has a case that did not pass, and does not release the VM', function (): void {
    [$operation, $claim] = runningProof();

    expect(fn () => app(ProofRunProjection::class)->apply($operation, proofRun('succeeded', ['status' => 'succeeded', 'summary' => [['p', 2], ['f', 1]], 'exit' => 0, 'cases_ran' => true])))
        ->toThrow(ResourceOperationException::class);

    expect($operation->refresh()->status->value)->toBe('running')->and($claim->released)->toBe([]);
});

it('reads a verdict line with the wrong number of cases as unrun', function (): void {
    [$operation] = runningProof();

    app(ProofRunProjection::class)->apply($operation, proofRun('failed', ['status' => 'failed', 'summary' => [['p', 2]], 'exit' => 1, 'cases_ran' => true], 'failed'));

    expect(array_column($operation->refresh()->result['cases'], 'verdict'))->toBe(['unrun', 'unrun']);
});

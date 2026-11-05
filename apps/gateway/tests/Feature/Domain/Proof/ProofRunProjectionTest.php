<?php

declare(strict_types=1);

use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofRunProjection;
use App\Jobs\Proof\CancelProofGroup;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

const PROOF_RUN_ID = '2c1f0a3e-7b8d-4e51-9a60-3d4f5e6a7b81';

const PROOF_RUN_TIMES = ['run_id' => PROOF_RUN_ID, 'started_at' => '2026-11-05T19:00:00Z', 'finished_at' => '2026-11-05T19:10:00Z'];

/** A proof.run that Swamp is running: two commands of the selection, a cancel capture, and a group that no longer exists. */
function runningProof(): Operation
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'swamp', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.9', 'wireguard_ip' => '10.44.0.9', 'user' => 'orbit']));

    return Operation::query()->create(['kind' => 'proof.run', 'target_type' => 'proof', 'target_id' => 7, 'node_id' => $node->id, 'status' => OperationStatus::Running,
        'sequence' => 1, 'attempts' => 1, 'started_at' => now(), 'state' => ['swamp_run_id' => PROOF_RUN_ID],
        'input' => ['commit' => str_repeat('a', 40), 'group_id' => 7, 'uncovered' => [], 'checkout_path' => '/home/orbit/apps/proof/workspace',
            'commands' => [['command' => 'firewall:allow', 'file' => 'swamp/proof/firewall/allow.cases.yaml'], ['command' => 'firewall:list', 'file' => 'swamp/proof/firewall/list.cases.yaml']],
            'cancel' => ['task_vm_id' => 3, 'workspace_routes' => []]]]);
}

describe('the result of the proof run', function (): void {
    it('records the verdict of each command once, from the reports of the run, and ends a group the run could not', function (): void {
        Queue::fake();
        $operation = runningProof();
        $report = '{"id": "new-restricted-allow", "verdict": "passed", "secs": 12}'."\n".'{"id": "duplicate", "verdict": "failed", "cause": "assertion", "secs": 3, "failed": ["ufw rule missing"]}';

        $projected = app(ProofRunProjection::class)->apply($operation, [...PROOF_RUN_TIMES, 'status' => 'succeeded', 'result' => [
            'steps' => ['cancel' => ['status' => 'failed']], 'report_0' => $report, 'report_1' => '']]);

        expect($projected->status)->toBe(OperationStatus::Failed)->and($projected->error_code)->toBe('proof.cases_failed')
            ->and(array_column($projected->result['cases'], 'program'))->toBe([
                'swamp/proof/firewall/allow.cases.yaml#new-restricted-allow', 'swamp/proof/firewall/allow.cases.yaml#duplicate', 'swamp/proof/firewall/list.cases.yaml'])
            ->and(array_column($projected->result['cases'], 'verdict'))->toBe(['passed', 'failed', 'unrun'])
            ->and($projected->result['evidence'])->toBe('/home/orbit/apps/proof/workspace/.orbit-artifacts/7/verify');
        Queue::assertPushed(CancelProofGroup::class);
    });

    it('fails with the run error when no case ran, because the VM or the fleet never became ready', function (): void {
        Queue::fake();

        $projected = app(ProofRunProjection::class)->apply(runningProof(), [...PROOF_RUN_TIMES, 'status' => 'failed', 'result' => ['steps' => ['provision' => ['status' => 'failed']]]]);

        expect($projected->status)->toBe(OperationStatus::Failed)->and($projected->error_code)->toBe('proof.run_failed')
            ->and(array_column($projected->result['cases'], 'verdict'))->toBe(['unrun', 'unrun']);
    });

    it('keeps a cancelled run cancelled and still ends its group', function (): void {
        Queue::fake();

        $projected = app(ProofRunProjection::class)->apply(runningProof(), [...PROOF_RUN_TIMES, 'status' => 'cancelled', 'result' => ['steps' => []]]);

        expect($projected->status)->toBe(OperationStatus::Cancelled);
        Queue::assertPushed(CancelProofGroup::class);
    });
});

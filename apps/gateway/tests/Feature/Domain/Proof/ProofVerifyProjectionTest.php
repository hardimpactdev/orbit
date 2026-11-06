<?php

declare(strict_types=1);

use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofVerifyProjection;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

const VERIFY_RUN_ID = '0f6a3a2e-5b0c-4b51-93a4-6f1d6c9d0a11';
const VERIFY_PASSED = '{"id": "main", "verdict": "passed", "secs": 9.5}';
const VERIFY_FAILED = '{"id": "deny", "verdict": "failed", "cause": "assertion", "secs": 4, "failed": ["ufw"]}';
const VERIFY_UNRUN = '{"id": "late", "verdict": "unrun", "cause": "infrastructure", "secs": 0, "failed": ["run stopped: the fleet or the batch setup did not succeed"]}';

/** The `proof.verify` Operation as the Gateway records it at admission, with its run bound. */
function verifyOperation(): Operation
{
    $guest = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'tvm-700', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '10.44.0.150', 'wireguard_ip' => '10.44.0.150', 'user' => 'orbit']));
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Work', 'brief' => 'Brief', 'status' => 'running']);

    return Operation::query()->create(['kind' => 'proof.verify', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $guest->id,
        'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => VERIFY_RUN_ID], 'input' => [
            'group_id' => $group->id, 'instance_id' => 1, 'node_id' => $guest->id, 'command' => 'firewall:allow', 'file' => 'swamp/proof/firewall/allow.cases.yaml',
            'checkout_path' => '/home/orbit/apps/orbit/workspace', 'ssh_execution' => ['target' => ['node_id' => $guest->id]],
            'restore_first' => true, 'final_restore' => true, 'execution_secrets' => [],
        ]]);
}

function verifyEnded(string $status, string $report = ''): array
{
    return ['run_id' => VERIFY_RUN_ID, 'status' => $status, 'started_at' => now()->subMinute()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => $report === '' ? [] : ['report' => ['status' => 'succeeded', 'stdout' => $report]], 'summary' => ['status' => $status, 'failed_steps' => []]]];
}

describe('the inputs of the run', function (): void {
    it('hands the admitted intent to the workflow, with no execution secrets', function (): void {
        $operation = verifyOperation();

        $input = app(ProofVerifyProjection::class)->input($operation);

        expect($input['execution_secrets'])->toBe([])
            ->and($input['intent'])->toMatchArray(['command' => 'firewall:allow', 'file' => 'swamp/proof/firewall/allow.cases.yaml', 'restore_first' => true, 'final_restore' => true])
            ->and($input['intent'])->not->toHaveKey('execution_secrets');
    });
});

describe('the record of the run', function (): void {
    it('records a pass as succeeded, with one row for each case', function (): void {
        $projected = app(ProofVerifyProjection::class)->apply(verifyOperation(), verifyEnded('succeeded', VERIFY_PASSED));

        expect($projected->status)->toBe(OperationStatus::Succeeded)->and($projected->error_code)->toBeNull()
            ->and($projected->result)->toMatchArray(['command' => 'firewall:allow', 'file' => 'swamp/proof/firewall/allow.cases.yaml', 'outcome' => 'passed'])
            ->and($projected->result['cases'])->toHaveCount(1)
            ->and($projected->result['cases'][0])->toMatchArray(['program' => 'swamp/proof/firewall/allow.cases.yaml#main', 'command' => 'firewall:allow', 'case' => 'main', 'verdict' => 'passed']);
    });

    it('records a failed case as proof.cases_failed, with the rows of every case', function (): void {
        $projected = app(ProofVerifyProjection::class)->apply(verifyOperation(), verifyEnded('succeeded', VERIFY_PASSED."\n".VERIFY_FAILED));

        expect($projected->status)->toBe(OperationStatus::Failed)->and($projected->error_code)->toBe('proof.cases_failed')
            ->and(array_column($projected->result['cases'], 'verdict'))->toBe(['passed', 'failed'])
            ->and($projected->result['cases'][1])->toMatchArray(['case' => 'deny', 'cause' => 'assertion', 'failed' => ['ufw']]);
    });

    it('records a run whose cases all passed but whose last restore failed as proof.restore_failed, and keeps the verdicts', function (): void {
        $projected = app(ProofVerifyProjection::class)->apply(verifyOperation(), verifyEnded('failed', VERIFY_PASSED));

        expect($projected->status)->toBe(OperationStatus::Failed)->and($projected->error_code)->toBe('proof.restore_failed')
            ->and($projected->result['outcome'])->toBe('failed')
            ->and(array_column($projected->result['cases'], 'verdict'))->toBe(['passed']);
    });

    it('records a run that stopped before its cases as proof.run_failed, with the unrun rows', function (): void {
        $projected = app(ProofVerifyProjection::class)->apply(verifyOperation(), verifyEnded('failed', VERIFY_UNRUN));

        expect($projected->error_code)->toBe('proof.run_failed')->and(array_column($projected->result['cases'], 'verdict'))->toBe(['unrun']);
    });

    it('records a run that printed no report as one unrun row', function (): void {
        $projected = app(ProofVerifyProjection::class)->apply(verifyOperation(), verifyEnded('failed'));

        expect($projected->error_code)->toBe('proof.run_failed')
            ->and($projected->result['cases'])->toBe([['program' => 'swamp/proof/firewall/allow.cases.yaml', 'command' => 'firewall:allow', 'verdict' => 'unrun', 'seconds' => 0]]);
    });

    it('answers a repeat of the terminal event with what it recorded', function (): void {
        $operation = verifyOperation();
        $first = app(ProofVerifyProjection::class)->apply($operation, verifyEnded('succeeded', VERIFY_PASSED));

        $again = app(ProofVerifyProjection::class)->apply($operation, verifyEnded('succeeded', VERIFY_PASSED));

        expect($again->status)->toBe(OperationStatus::Succeeded)->and($again->result)->toBe($first->result);
    });
});

<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofCommandProjection;
use App\Domain\Proof\ProofRunSettlement;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Jobs\Proof\CancelProofGroup;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

const PROOF_RUN_ID = '6b69badf-4f06-4f2e-82d7-21b62c1f587a';

/** The `proof.run` of the CLI with `$commands` runs, each as the Gateway records it at admission with its VM still provisioning. */
function proofRuns(array $commands): array
{
    $node = static fn (string $name, string $address): Node => Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => $address, 'public_ssh_port' => 22,
        'wireguard_ip' => $address, 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $host = $node('shark', '10.44.0.11');
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    $proof = Operation::query()->create(['kind' => 'proof.run', 'target_type' => 'node', 'target_id' => $host->id, 'node_id' => $host->id, 'status' => OperationStatus::Running,
        'input' => ['commit' => str_repeat('a', 40), 'uncovered' => [], 'runs' => []]]);
    $runs = [];
    $records = [];
    foreach ($commands as $index => $command) {
        $guest = $node('tvm-'.(600 + $index), '10.44.0.'.(140 + $index));
        $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Proof '.$command, 'brief' => 'Brief', 'status' => TaskGroupStatus::Reserved]);
        $vm = TaskVm::query()->create(['host_node_id' => $host->id, 'node_id' => $guest->id, 'group_id' => $group->id, 'provider' => TaskVmProviderKind::Incus, 'name' => 'tvm-'.(600 + $index),
            'state' => TaskVmState::Provisioning, 'address' => '10.251.78.'.(40 + $index), 'wireguard_ip' => '10.44.0.'.(140 + $index), 'size' => TaskVmSize::Normal,
            'image_fingerprint' => 'fingerprint', 'recipe_identity' => 'recipe']);
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $guest->id, 'name' => 'workspace-'.$index, 'source_layout' => InstanceSourceLayout::Checkout,
            'source_prepare_id' => 'prepare-'.$index, 'checkout_path' => '/home/orbit/apps/orbit/workspace', 'task_workspace_owner_id' => $group->id,
            'status' => InstanceState::Reserved, 'first_setup_pending' => true]);
        $file = 'swamp/proof/'.str_replace(':', '/', $command).'.cases.yaml';
        $run = Operation::query()->create(['kind' => 'proof.command', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $host->id, 'status' => OperationStatus::Running,
            'state' => ['swamp_run_id' => PROOF_RUN_ID], 'input' => [
                'proof_operation_id' => $proof->id, 'commit' => str_repeat('a', 40), 'command' => $command, 'file' => $file, 'group_id' => $group->id, 'instance_id' => $instance->id,
                'node_id' => $host->id, 'execution_secrets' => [],
                'provision' => ['task_vm_id' => $vm->id, 'node_id' => $host->id, 'host_node_id' => $host->id, 'guest_node_id' => $guest->id, 'name' => $vm->name, 'provider' => 'incus',
                    'provider_id' => null, 'wireguard_ip' => $vm->wireguard_ip, 'image_fingerprint' => 'fingerprint', 'recipe_identity' => 'recipe', 'instance_id' => $instance->id,
                    'enrollment' => [], 'route_id' => null, 'members' => []],
                'verify' => ['group_id' => $group->id, 'file' => $file], 'cancel' => ['task_vm_id' => $vm->id, 'node_id' => $host->id],
            ]]);
        $runs[] = ['command' => $command, 'file' => $file, 'group_id' => $group->id, 'operation_id' => $run->id];
        $records[] = [$run, $group, $vm];
    }
    $proof->forceFill(['input' => [...$proof->input, 'runs' => $runs]])->save();

    return [$proof, $records];
}

function proofEnded(string $status, array $steps, string $report = ''): array
{
    return ['run_id' => PROOF_RUN_ID, 'status' => $status, 'started_at' => now()->subMinute()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => [...$steps, ...($report === '' ? [] : ['Report' => ['status' => 'succeeded', 'stdout' => $report]])], 'summary' => ['status' => $status, 'failed_steps' => array_keys(array_filter($steps, static fn (array $step): bool => $step['status'] === 'failed'))]]];
}

const PROOF_PASSED = '{"id": "main", "verdict": "passed", "secs": 9.5}';
const PROOF_FAILED = '{"id": "deny", "verdict": "failed", "cause": "assertion", "secs": 4, "failed": ["ufw"]}';

describe('the inputs of one run', function (): void {
    it('refuses a provision without the converge intent that the workflow takes as an input of its own', function (): void {
        [, [[$run]]] = proofRuns(['firewall:allow']);

        expect(fn () => app(ProofCommandProjection::class)->input($run))->toThrow(ResourceOperationException::class, 'nested topology');
    });
});

describe('the record of one run', function (): void {
    it('fails the run, with one unrun row, when the VM never became ready, and cancels the group the normal way', function (): void {
        Queue::fake();
        [$proof, [[$run, $group, $vm]]] = proofRuns(['firewall:allow']);

        $projected = app(ProofCommandProjection::class)->apply($run, proofEnded('failed', ['Create the VM' => ['status' => 'failed']]));

        expect($projected->status)->toBe(OperationStatus::Failed)
            ->and($projected->error_code)->toBe('proof.run_failed')
            ->and($projected->result['cases'])->toBe([['program' => 'swamp/proof/firewall/allow.cases.yaml', 'command' => 'firewall:allow', 'verdict' => 'unrun', 'seconds' => 0]])
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Failed)
            ->and($vm->refresh()->state)->toBe(TaskVmState::Failed);
        Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $run->id);
        expect($proof->refresh()->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('proof.run_failed');
    });

    it('records a failed case as proof.cases_failed, with the rows of every case', function (): void {
        Queue::fake();
        [$proof, [[$run]]] = proofRuns(['firewall:allow']);

        $projected = app(ProofCommandProjection::class)->apply($run, proofEnded('failed', ['Create the VM' => ['status' => 'succeeded'], 'Destroy the VM' => ['status' => 'failed']], PROOF_PASSED."\n".PROOF_FAILED));

        expect($projected->error_code)->toBe('proof.cases_failed')
            ->and(array_column($projected->result['cases'], 'verdict'))->toBe(['passed', 'failed'])
            ->and($projected->result['cases'][1])->toMatchArray(['program' => 'swamp/proof/firewall/allow.cases.yaml#deny', 'command' => 'firewall:allow', 'case' => 'deny', 'cause' => 'assertion', 'failed' => ['ufw']])
            ->and($proof->refresh()->error_code)->toBe('proof.cases_failed');
    });

    it('refuses a run that succeeded and reports no ready VM', function (): void {
        [, [[$run]]] = proofRuns(['firewall:allow']);

        expect(fn () => app(ProofCommandProjection::class)->apply($run, proofEnded('succeeded', ['Destroy the VM' => ['status' => 'succeeded']], PROOF_PASSED)))
            ->toThrow(ResourceOperationException::class);
    });

    it('records a run once', function (): void {
        Queue::fake();
        [, [[$run]]] = proofRuns(['firewall:allow']);
        $ended = proofEnded('failed', ['Create the VM' => ['status' => 'failed']]);

        $first = app(ProofCommandProjection::class)->apply($run, $ended);
        $again = app(ProofCommandProjection::class)->apply($run->refresh(), $ended);

        expect($again->sequence)->toBe($first->sequence);
    });
});

describe('the proof of the CLI', function (): void {
    it('stays running until the last run has ended', function (): void {
        Queue::fake();
        [$proof, [[$first], [$second]]] = proofRuns(['firewall:allow', 'firewall:deny']);

        app(ProofCommandProjection::class)->apply($first, proofEnded('failed', ['Create the VM' => ['status' => 'failed']]));
        expect($proof->refresh()->status)->toBe(OperationStatus::Running);

        app(ProofCommandProjection::class)->apply($second, proofEnded('failed', ['Create the VM' => ['status' => 'failed']]));
        expect($proof->refresh()->status)->toBe(OperationStatus::Failed)
            ->and(array_column($proof->result['cases'], 'command'))->toBe(['firewall:allow', 'firewall:deny']);
    });

    it('passes when every run passed', function (): void {
        [$proof, [[$first], [$second]]] = proofRuns(['firewall:allow', 'firewall:deny']);
        foreach ([$first, $second] as $run) {
            $run->forceFill(['status' => OperationStatus::Succeeded, 'result' => ['cases' => [['program' => 'p', 'command' => $run->input['command'], 'case' => 'main', 'verdict' => 'passed', 'seconds' => 1]]]])->save();
        }

        app(ProofRunSettlement::class)->settle($proof->id);

        expect($proof->refresh()->status)->toBe(OperationStatus::Succeeded)->and($proof->result['outcome'])->toBe('passed')->and($proof->result['cases'])->toHaveCount(2);
    });

    it('fails when one run failed, though the other passed', function (): void {
        [$proof, [[$first], [$second]]] = proofRuns(['firewall:allow', 'firewall:deny']);
        $first->forceFill(['status' => OperationStatus::Succeeded, 'result' => ['cases' => [['program' => 'p', 'command' => 'firewall:allow', 'case' => 'main', 'verdict' => 'passed', 'seconds' => 1]]]])->save();
        $second->forceFill(['status' => OperationStatus::Failed, 'result' => ['cases' => [['program' => 'p', 'command' => 'firewall:deny', 'case' => 'main', 'verdict' => 'failed', 'seconds' => 1]]]])->save();

        app(ProofRunSettlement::class)->settle($proof->id);

        expect($proof->refresh()->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('proof.cases_failed');
    });

    it('closes once and ignores a proof with no runs yet', function (): void {
        [$proof, [[$run]]] = proofRuns(['firewall:allow']);
        $run->forceFill(['status' => OperationStatus::Succeeded, 'result' => ['cases' => [['program' => 'p', 'command' => 'firewall:allow', 'case' => 'main', 'verdict' => 'passed', 'seconds' => 1]]]])->save();

        app(ProofRunSettlement::class)->settle($proof->id);
        $closed = $proof->refresh();
        app(ProofRunSettlement::class)->settle($proof->id);

        expect($closed->status)->toBe(OperationStatus::Succeeded)->and($closed->result['outcome'])->toBe('passed')
            ->and($proof->refresh()->sequence)->toBe($closed->sequence);

        $empty = Operation::query()->create(['kind' => 'proof.run', 'target_type' => 'node', 'target_id' => 1, 'node_id' => 1, 'status' => OperationStatus::Running,
            'input' => ['commit' => str_repeat('a', 40), 'uncovered' => [], 'runs' => []]]);
        app(ProofRunSettlement::class)->settle($empty->id);
        expect($empty->refresh()->status)->toBe(OperationStatus::Running);
    });
});

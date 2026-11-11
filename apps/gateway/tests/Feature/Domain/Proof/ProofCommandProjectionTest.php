<?php

declare(strict_types=1);

use App\Actions\Operations\ExpireOperationAction;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Proof\ProofBatchProjection;
use App\Domain\Proof\ProofCommandProjection;
use App\Domain\Proof\ProofRunSettlement;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampConnection;
use App\Infrastructure\Swamp\SwampTerminalReadUnavailable;
use App\Jobs\Proof\CancelProofGroup;
use App\Jobs\TaskVms\ReleaseTaskVmSlot;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\NativeRunBudgetSocket;

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
            'slot_number' => $index + 1, 'slot_project_id' => $project->id, 'state' => TaskVmState::Provisioning, 'address' => '10.251.78.'.(40 + $index), 'wireguard_ip' => '10.44.0.'.(140 + $index), 'size' => TaskVmSize::Normal,
            'image_fingerprint' => 'fingerprint', 'recipe_identity' => 'recipe']);
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $guest->id, 'name' => 'workspace-'.$index, 'source_layout' => InstanceSourceLayout::Checkout,
            'source_prepare_id' => 'prepare-'.$index, 'checkout_path' => '/home/orbit/apps/orbit/workspace', 'task_workspace_owner_id' => $group->id,
            'status' => InstanceState::Reserved, 'first_setup_pending' => true]);
        $file = 'swamp/proof/'.str_replace(':', '/', $command).'.cases.yaml';
        $run = Operation::query()->create(['kind' => 'proof.command', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $host->id, 'status' => OperationStatus::Running,
            'state' => ['swamp_run_id' => PROOF_RUN_ID], 'input' => [
                'proof_operation_id' => $proof->id, 'commit' => str_repeat('a', 40), 'command' => $command, 'file' => $file, 'group_id' => $group->id, 'instance_id' => $instance->id,
                'node_id' => $host->id, 'execution_secrets' => [],
                'provision' => ['task_vm_id' => $vm->id, 'node_id' => $host->id, 'group_id' => $group->id, 'instance_id' => $instance->id,
                    'workspace' => ['branch' => 'task-'.$group->id, 'start_commit' => str_repeat('a', 40)], 'intent' => ['slot' => ['name' => 'shark-tvm-1']]],
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
        'result' => ['report' => implode("\n", array_map(static fn (string $line): string => "firewall:allow\tswamp/proof/firewall/allow.cases.yaml\t\t".$line, array_filter(explode("\n", $report)))), 'steps' => [...$steps, ...($report === '' ? [] : ['Report' => ['status' => 'succeeded', 'stdout' => $report]])], 'summary' => ['status' => $status, 'failed_steps' => array_keys(array_filter($steps, static fn (array $step): bool => $step['status'] === 'failed'))]]];
}

const PROOF_PASSED = '{"id": "main", "verdict": "passed", "secs": 9.5}';
const PROOF_FAILED = '{"id": "deny", "verdict": "failed", "cause": "assertion", "secs": 4, "failed": ["ufw"]}';

describe('the inputs of one run', function (): void {
    it('passes the claim, the cases and the release of the slot, with the claim\'s vault key names', function (): void {
        [, [[$run]]] = proofRuns(['firewall:allow']);

        $input = app(ProofCommandProjection::class)->input($run);
        expect($input['intent'])->toHaveKeys(['provision', 'verify', 'cancel'])->and($input['intent']['provision'])->toBe(['slot' => ['name' => 'shark-tvm-1']])
            ->and($input)->not->toHaveKey('converge');
    });
});

describe('the keep_failed input of one run', function (): void {
    it('goes to the workflow only for a proof that was started with it', function (array $input, array $expected): void {
        expect(ProofCommandProjection::keepFlag($input))->toBe($expected);
    })->with([
        'asked for' => [['keep_failed' => true], ['keep_failed' => true]],
        'not mentioned' => [[], []],
        'false' => [['keep_failed' => false], []],
        'a string is not a yes' => [['keep_failed' => 'true'], []],
    ]);

    it('is an input the workflow declares, and its destroy step is guarded on it and on the steps that the Gateway reads', function (): void {
        $workflow = Yaml::parseFile(dirname(base_path(), 2).'/swamp/workflows/workflow-operation-proof-run.yaml');
        $steps = collect($workflow['jobs'])->flatMap(static fn (array $job): array => $job['steps'])->keyBy('name');
        $destroy = $steps['Release the place'];

        // The workflow takes exactly what the Gateway sends: a flag it does not declare would be refused at the start.
        expect($workflow['inputs']['properties']['keep_failed'])->toBe(['type' => 'boolean'])
            ->and(array_keys(ProofCommandProjection::keepFlag(['keep_failed' => true])))->each->toBeIn(array_keys($workflow['inputs']['properties']))
            // Swamp skips a step whose guard is true, so the guard names the flag and the two steps whose outcome keeps the VM.
            ->and($destroy['guard'])->toContain('inputs.?keep_failed', 'steps[?"Claim a place"]', 'inputs.intent.verifications.all')
            // The destroy step is still one of the steps that the projection reads, and the report waits for it whether it ran or was skipped.
            ->and($destroy['dependsOn'])->toContain(['step' => 'Prove assigned batches', 'condition' => ['type' => 'always']])
            ->and(collect($steps['Report']['dependsOn'])->firstWhere('step', 'Release the place')['condition'])->toBe(['type' => 'always']);
    });
});
describe('the record of one run', function (): void {
    it('fails the run, with one unrun row, when the claim did not pass, and cancels the group the normal way', function (): void {
        Queue::fake();
        [$proof, [[$run, $group, $vm]]] = proofRuns(['firewall:allow']);

        $projected = app(ProofCommandProjection::class)->apply($run, proofEnded('failed', ['Claim a place' => ['status' => 'failed']]));

        expect($projected->status)->toBe(OperationStatus::Failed)
            ->and($projected->error_code)->toBe('proof.run_failed')
            ->and($projected->result['cases'])->toHaveCount(1)
            ->and($projected->result['cases'][0])->toMatchArray(['program' => 'swamp/proof/firewall/allow.cases.yaml', 'command' => 'firewall:allow', 'verdict' => 'unrun', 'seconds' => 0])
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Failed)
            // The run released the slot itself; the claim's record starts no second release.
            ->and($vm->refresh()->group_id)->toBe($group->id);
        Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $run->id);
        Queue::assertNotPushed(ReleaseTaskVmSlot::class);
        expect($proof->refresh()->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('proof.run_failed');
    });

    it('records a failed case as proof.cases_failed, with the rows of every case', function (): void {
        Queue::fake();
        [$proof, [[$run]]] = proofRuns(['firewall:allow']);

        $projected = app(ProofCommandProjection::class)->apply($run, proofEnded('failed', ['Claim a place' => ['status' => 'succeeded'], 'Release the place' => ['status' => 'failed']], PROOF_PASSED."\n".PROOF_FAILED));

        expect($projected->error_code)->toBe('proof.cases_failed')
            ->and(array_column($projected->result['cases'], 'verdict'))->toBe(['passed', 'failed'])
            ->and($projected->result['cases'][1])->toMatchArray(['program' => 'swamp/proof/firewall/allow.cases.yaml#deny', 'command' => 'firewall:allow', 'case' => 'deny', 'cause' => 'assertion', 'failed' => ['ufw']])
            ->and($proof->refresh()->error_code)->toBe('proof.cases_failed');
    });

    it('refuses a run that succeeded and reports no ready VM', function (): void {
        [, [[$run]]] = proofRuns(['firewall:allow']);

        expect(fn () => app(ProofCommandProjection::class)->apply($run, proofEnded('succeeded', ['Release the place' => ['status' => 'succeeded']], PROOF_PASSED)))
            ->toThrow(ResourceOperationException::class);
    });

    it('records a run once', function (): void {
        Queue::fake();
        [, [[$run]]] = proofRuns(['firewall:allow']);
        $ended = proofEnded('failed', ['Claim a place' => ['status' => 'failed']]);

        $first = app(ProofCommandProjection::class)->apply($run, $ended);
        $again = app(ProofCommandProjection::class)->apply($run->refresh(), $ended);

        expect($again->sequence)->toBe($first->sequence);
    });
});

describe('the proof of the CLI', function (): void {
    it('stays running until the last run has ended', function (): void {
        Queue::fake();
        [$proof, [[$first], [$second]]] = proofRuns(['firewall:allow', 'firewall:deny']);

        app(ProofCommandProjection::class)->apply($first, proofEnded('failed', ['Claim a place' => ['status' => 'failed']]));
        expect($proof->refresh()->status)->toBe(OperationStatus::Running);

        app(ProofCommandProjection::class)->apply($second, proofEnded('failed', ['Claim a place' => ['status' => 'failed']]));
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

/** The terminal event of a run that got its VM ready and proved the command: the workflow skipped or ran its destroy as `$destroy` says. */
function proofKeptEnd(TaskVm $vm, string $report, string $destroy = 'skipped', array $steps = []): array
{
    // A VM that is no longer provisioning keeps its state, so the enrollment of the receipt records nothing here.
    $vm->update(['state' => TaskVmState::Ready]);
    $ended = proofEnded('failed', ['Claim a place' => ['status' => 'succeeded'], 'Prove the command' => ['status' => 'succeeded'],
        'Release the place' => ['status' => $destroy], ...$steps], $report);
    $ended['result']['provision'] = ['task_vm_id' => $vm->id, 'guest_node_id' => $vm->node_id, 'ready' => true, 'enrollment' => ['ready' => true],
        'provider' => ['image_fingerprint' => 'fingerprint', 'running' => true, 'settled' => true], 'source' => ['branch' => 'main', 'commit' => str_repeat('a', 40), 'php_version' => '8.5', 'laravel' => false]];

    return $ended;
}

describe('keep_failed', function (): void {
    afterEach(fn () => Carbon::setTestNow());

    it('keeps the VM and the group of a failed run, for 60 minutes, and records them on the run and on the proof', function (): void {
        Queue::fake();
        Carbon::setTestNow('2026-11-07 10:00:00');
        [$proof, [[$run, $group, $vm]]] = proofRuns(['firewall:allow']);
        $run->forceFill(['input' => [...$run->input, 'keep_failed' => true]])->save();

        $projected = app(ProofCommandProjection::class)->apply($run, proofKeptEnd($vm, PROOF_PASSED."\n".PROOF_FAILED));

        $kept = ['vm' => 'tvm-600', 'group_id' => $group->id, 'keep_until' => '2026-11-07T11:00:00Z', 'cancel_requested_at' => null];
        expect($projected->status)->toBe(OperationStatus::Failed)->and($projected->error_code)->toBe('proof.cases_failed')->and($projected->result['kept'])->toBe($kept)
            ->and($group->refresh()->status)->toBe(TaskGroupStatus::Running)->and($vm->refresh()->state)->toBe(TaskVmState::Ready)
            ->and($proof->refresh()->status)->toBe(OperationStatus::Failed)
            ->and($proof->result['kept'])->toBe([['command' => 'firewall:allow', 'vm' => 'tvm-600', 'group_id' => $group->id, 'keep_until' => '2026-11-07T11:00:00Z']]);
        Queue::assertNotPushed(CancelProofGroup::class);
    });

    it('keeps the VM of a run that Swamp ended as succeeded, because the cases printed a failed verdict', function (): void {
        Queue::fake();
        [, [[$run, , $vm]]] = proofRuns(['firewall:allow']);
        $run->forceFill(['input' => [...$run->input, 'keep_failed' => true]])->save();
        $ended = proofKeptEnd($vm, PROOF_FAILED);
        $ended['status'] = 'succeeded';

        $projected = app(ProofCommandProjection::class)->apply($run, $ended);

        expect($projected->status)->toBe(OperationStatus::Failed)->and($projected->error_code)->toBe('proof.cases_failed')->and($projected->result['kept']['vm'])->toBe('tvm-600');
        Queue::assertNotPushed(CancelProofGroup::class);
    });

    it('keeps one VM of two and leaves the proof open until the other run has ended', function (): void {
        Queue::fake();
        [$proof, [[$first, , $firstVm], [$second]]] = proofRuns(['firewall:allow', 'firewall:deny']);
        $first->forceFill(['input' => [...$first->input, 'keep_failed' => true]])->save();

        app(ProofCommandProjection::class)->apply($first, proofKeptEnd($firstVm, PROOF_FAILED));
        expect($proof->refresh()->status)->toBe(OperationStatus::Running);

        app(ProofCommandProjection::class)->apply($second, proofEnded('failed', ['Claim a place' => ['status' => 'failed']]));
        expect($proof->refresh()->status)->toBe(OperationStatus::Failed)->and(array_column($proof->result['kept'], 'command'))->toBe(['firewall:allow']);
        Queue::assertPushed(CancelProofGroup::class, 1);
        Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $second->id);
    });
    it('cancels the group the normal way when the flag is off, and records nothing kept', function (): void {
        Queue::fake();
        [$proof, [[$run, , $vm]]] = proofRuns(['firewall:allow']);

        $projected = app(ProofCommandProjection::class)->apply($run, proofKeptEnd($vm, PROOF_FAILED));

        expect($projected->result)->not->toHaveKey('kept')->and($proof->refresh()->result)->not->toHaveKey('kept');
        Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $run->id);
    });

    it('cancels the group the normal way when the destroy step ran and failed, although the flag is on', function (array $steps, string $destroy): void {
        Queue::fake();
        [, [[$run, , $vm]]] = proofRuns(['firewall:allow']);
        $run->forceFill(['input' => [...$run->input, 'keep_failed' => true]])->save();

        $projected = app(ProofCommandProjection::class)->apply($run, proofKeptEnd($vm, PROOF_FAILED, $destroy, $steps));

        expect($projected->result)->not->toHaveKey('kept');
        Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $run->id);
    })->with([
        'a destroy that failed' => [[], 'failed'],
    ]);

    it('keeps the VM of a run that failed in its setup, whatever the step, with the VM in the state the failure left it', function (array $steps): void {
        Queue::fake();
        Carbon::setTestNow('2026-11-07 10:00:00');
        [$proof, [[$run, $group, $vm]]] = proofRuns(['firewall:list']);
        $run->forceFill(['input' => [...$run->input, 'keep_failed' => true]])->save();
        // M5 try 11: the nested fleet did not start inside `Create the VM`, so the run never pinned the VM and never ran a case.
        $ended = proofEnded('failed', [...$steps, 'Prove the command' => ['status' => 'skipped'], 'Release the place' => ['status' => 'skipped']]);

        $projected = app(ProofCommandProjection::class)->apply($run, $ended);

        $kept = ['vm' => 'tvm-600', 'group_id' => $group->id, 'keep_until' => '2026-11-07T11:00:00Z', 'cancel_requested_at' => null];
        expect($projected->status)->toBe(OperationStatus::Failed)->and($projected->error_code)->toBe('proof.run_failed')->and($projected->result['kept'])->toBe($kept)
            ->and($vm->refresh()->state)->not->toBe(TaskVmState::Destroyed)->and($group->refresh()->status)->toBe(TaskGroupStatus::Failed)
            ->and($proof->refresh()->result['kept'])->toBe([['command' => 'firewall:list', 'vm' => 'tvm-600', 'group_id' => $group->id, 'keep_until' => '2026-11-07T11:00:00Z']]);
        Queue::assertNotPushed(CancelProofGroup::class);
    })->with([
        'the VM step failed' => [['Claim a place' => ['status' => 'failed']]],
        'the nested fleet did not start' => [['Claim a place' => ['status' => 'failed'], 'Start the nested fleet' => ['status' => 'failed'], 'Pin the nested CA' => ['status' => 'failed']]],
    ]);

    it('cancels the group the normal way when the same setup failure happens without the flag', function (): void {
        Queue::fake();
        [, [[$run]]] = proofRuns(['firewall:list']);
        $ended = proofEnded('failed', ['Claim a place' => ['status' => 'failed'], 'Release the place' => ['status' => 'failed']]);

        $projected = app(ProofCommandProjection::class)->apply($run, $ended);

        expect($projected->result)->not->toHaveKey('kept');
        Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $run->id);
    });

    it('keeps nothing for a run that never got a VM, and cancels its group the normal way', function (): void {
        Queue::fake();
        [, [[$run, , $vm]]] = proofRuns(['firewall:list']);
        $run->forceFill(['input' => [...$run->input, 'keep_failed' => true]])->save();
        $vm->update(['state' => TaskVmState::Destroyed]);

        $projected = app(ProofCommandProjection::class)->apply($run, proofEnded('failed', ['Claim a place' => ['status' => 'failed'], 'Release the place' => ['status' => 'skipped']]));

        expect($projected->result)->not->toHaveKey('kept');
        Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $run->id);
    });

    it('does not keep a VM for a run that passed', function (): void {
        Queue::fake();
        [, [[$run, , $vm]]] = proofRuns(['firewall:allow']);
        $run->forceFill(['input' => [...$run->input, 'keep_failed' => true]])->save();
        $ended = proofKeptEnd($vm, PROOF_PASSED);
        $ended['status'] = 'succeeded';

        $projected = app(ProofCommandProjection::class)->apply($run, $ended);

        expect($projected->status)->toBe(OperationStatus::Succeeded)->and($projected->result)->not->toHaveKey('kept');
    });
});

describe('the check of the workspace commit', function (): void {
    /** The steps of a run that got a VM and failed the check of its workspace, so that no case ran. */
    function proofWorkspaceSteps(string $printed): array
    {
        return ['Claim a place' => ['status' => 'succeeded'], 'Pin the VM' => ['status' => 'succeeded'],
            'Read the workspace commit' => ['status' => 'succeeded', 'stdout' => $printed],
            'Check the workspace commit' => ['status' => 'failed'], 'Prove the command' => ['status' => 'skipped'], 'Release the place' => ['status' => 'failed']];
    }

    it('records a workspace at another commit as proof.workspace_commit_mismatch, naming both commits, on the run and on the proof', function (): void {
        Queue::fake();
        [$proof, [[$run]]] = proofRuns(['firewall:allow']);

        $projected = app(ProofCommandProjection::class)->apply($run, proofEnded('failed', proofWorkspaceSteps(str_repeat('b', 40)."\n")));

        expect($projected->status)->toBe(OperationStatus::Failed)->and($projected->error_code)->toBe('proof.workspace_commit_mismatch')
            ->and($projected->result['workspace'])->toBe(['expected' => str_repeat('a', 40), 'found' => str_repeat('b', 40)])
            ->and(array_column($projected->result['cases'], 'verdict'))->toBe(['unrun']);
        expect($proof->refresh()->error_code)->toBe('proof.workspace_commit_mismatch')
            ->and($proof->result['workspace'])->toBe([['command' => 'firewall:allow', 'expected' => str_repeat('a', 40), 'found' => str_repeat('b', 40)]]);
    });

    it('records no commit when the VM printed none that looks like one, so no text of the VM reaches the result', function (string $printed): void {
        Queue::fake();
        [, [[$run]]] = proofRuns(['firewall:allow']);

        $projected = app(ProofCommandProjection::class)->apply($run, proofEnded('failed', proofWorkspaceSteps($printed)));

        expect($projected->error_code)->toBe('proof.workspace_commit_mismatch')->and($projected->result['workspace'])->toBe(['expected' => str_repeat('a', 40), 'found' => '']);
    })->with(['nothing' => [''], 'an error' => ["fatal: not a git repository\n"], 'a short id' => ['abc123']]);

    it('leaves the code to the cases when the check passed', function (): void {
        Queue::fake();
        [, [[$run]]] = proofRuns(['firewall:allow']);
        $steps = [...proofWorkspaceSteps(str_repeat('a', 40)), 'Check the workspace commit' => ['status' => 'succeeded'], 'Prove the command' => ['status' => 'succeeded']];

        $projected = app(ProofCommandProjection::class)->apply($run, proofEnded('failed', $steps, PROOF_PASSED."\n".PROOF_FAILED));

        expect($projected->error_code)->toBe('proof.cases_failed')->and($projected->result)->not->toHaveKey('workspace');
    });

    it('keeps the code of a cancelled run, whatever the check did', function (): void {
        Queue::fake();
        [, [[$run]]] = proofRuns(['firewall:allow']);

        $projected = app(ProofCommandProjection::class)->apply($run, proofEnded('cancelled', proofWorkspaceSteps(str_repeat('b', 40))));

        expect($projected->error_code)->not->toBe('proof.workspace_commit_mismatch')->and($projected->result)->not->toHaveKey('workspace');
    });
});

describe('the check of the workspace commit, with the end of the VM', function (): void {
    it('keeps the VM of a run that failed the check when keep_failed is on, and records the commits next to the kept VM', function (): void {
        Queue::fake();
        [, [[$run, , $vm]]] = proofRuns(['firewall:allow']);
        $run->forceFill(['input' => [...$run->input, 'keep_failed' => true]])->save();
        $ended = proofKeptEnd($vm, '', 'skipped', [
            'Read the workspace commit' => ['status' => 'succeeded', 'stdout' => str_repeat('b', 40)], 'Check the workspace commit' => ['status' => 'failed'], 'Prove the command' => ['status' => 'skipped'],
        ]);

        $projected = app(ProofCommandProjection::class)->apply($run, $ended);

        expect($projected->error_code)->toBe('proof.workspace_commit_mismatch')->and($projected->result['workspace'])->toBe(['expected' => str_repeat('a', 40), 'found' => str_repeat('b', 40)])
            ->and($projected->result['kept']['vm'])->toBe($vm->name);
        Queue::assertNotPushed(CancelProofGroup::class);
    });

    it('cancels the group the normal way when the flag is off, and records no kept VM', function (): void {
        Queue::fake();
        [, [[$run]]] = proofRuns(['firewall:allow']);
        $ended = proofEnded('failed', proofWorkspaceSteps(str_repeat('b', 40)));

        $projected = app(ProofCommandProjection::class)->apply($run, $ended);

        expect($projected->error_code)->toBe('proof.workspace_commit_mismatch')->and($projected->result)->not->toHaveKey('kept');
        Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $run->id);
    });

    it('lets the cases that ran decide the code of the proof, and still lists the commits of the run that did not', function (): void {
        [$proof, [[$first], [$second]]] = proofRuns(['firewall:allow', 'firewall:deny']);
        $first->forceFill(['status' => OperationStatus::Failed, 'error_code' => 'proof.cases_failed',
            'result' => ['cases' => [['program' => 'p', 'command' => 'firewall:allow', 'case' => 'main', 'verdict' => 'failed', 'seconds' => 1]]]])->save();
        $second->forceFill(['status' => OperationStatus::Failed, 'error_code' => 'proof.workspace_commit_mismatch',
            'result' => ['cases' => [['program' => 'p', 'command' => 'firewall:deny', 'verdict' => 'unrun', 'seconds' => 0]], 'workspace' => ['expected' => str_repeat('a', 40), 'found' => str_repeat('b', 40)]]])->save();

        app(ProofRunSettlement::class)->settle($proof->id);

        expect($proof->refresh()->error_code)->toBe('proof.cases_failed')
            ->and($proof->result['workspace'])->toBe([['command' => 'firewall:deny', 'expected' => str_repeat('a', 40), 'found' => str_repeat('b', 40)]]);
    });

    it('ends the proof with the mismatch code when no command ran a case, over a run that was interrupted', function (): void {
        [$proof, [[$first], [$second]]] = proofRuns(['firewall:allow', 'firewall:deny']);
        $first->forceFill(['status' => OperationStatus::Failed, 'error_code' => 'proof.workspace_commit_mismatch',
            'result' => ['cases' => [['program' => 'p', 'command' => 'firewall:allow', 'verdict' => 'unrun', 'seconds' => 0]], 'workspace' => ['expected' => str_repeat('a', 40), 'found' => '']]])->save();
        $second->forceFill(['status' => OperationStatus::Failed, 'error_code' => ProofRunSettlement::AdmissionInterrupted, 'result' => ['error_code' => ProofRunSettlement::AdmissionInterrupted]])->save();

        app(ProofRunSettlement::class)->settle($proof->id);

        expect($proof->refresh()->error_code)->toBe('proof.workspace_commit_mismatch')->and($proof->result['workspace'])->toHaveCount(1);
    });
});

it('merges full shard reports beyond the history tail, requires every case, and deletes each VM', function (bool $omit): void {
    Queue::fake();
    [$proof, [[$first, $firstGroup, $firstVm], [$second, $secondGroup, $secondVm]]] = proofRuns(['firewall:allow', 'firewall:deny']);
    $batches = [];
    foreach ([[$first, $firstVm], [$second, $secondVm]] as $index => [$run, $vm]) {
        $ids = array_map(static fn (int $number): string => 'case-'.$number, range(1, 120));
        $batch = ['command' => $run->input['command'], 'file' => $run->input['file'], 'batch' => 'fresh', 'cases' => $ids];
        $batches[] = $batch;
        $run->update(['input' => [...$run->input, 'ci_pool' => true, 'assignments' => [$batch]]]);
        $ended = proofKeptEnd($vm, '', 'succeeded');
        $ended['status'] = 'succeeded';
        $lines = [];
        foreach ($ids as $id) {
            if ($omit && $index === 1 && $id === 'case-120') {
                continue;
            }
            $lines[] = $batch['command']."\t".$batch['file']."\tfresh\t".json_encode(['id' => $id, 'verdict' => 'passed', 'secs' => 1]);
        }
        $ended['result']['report'] = implode("\n", $lines);
        // Native history keeps only a tail. The fixed capture supplies the full report independently.
        $ended['result']['steps']['Report'] = ['status' => 'succeeded', 'stdout' => substr($ended['result']['report'], -4096)];
        $proof->update(['input' => [...$proof->input, 'selected_commands' => ['firewall:allow', 'firewall:deny'], 'batches' => $batches,
            'cases' => array_map(static fn (array $batch): array => ['command' => $batch['command'], 'file' => $batch['file'], 'reasons' => array_fill_keys($batch['cases'], 'uses changed fixture')], $batches)]]);
        app(ProofCommandProjection::class)->apply($run, $ended);
        if ($index === 0) {
            expect($proof->refresh()->status)->toBe(OperationStatus::Running);
        }
    }
    expect($proof->refresh()->status)->toBe($omit ? OperationStatus::Failed : OperationStatus::Succeeded)
        ->and($proof->result['cases'])->toHaveCount(240)
        ->and(array_unique(array_column($proof->result['cases'], 'reason')))->toBe(['uses changed fixture'])
        ->and($firstGroup->refresh()->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($secondGroup->refresh()->status)->toBe(TaskGroupStatus::Cancelled)
        ->and($firstVm->refresh()->state)->toBe(TaskVmState::Destroyed)
        ->and($secondVm->refresh()->state)->toBe(TaskVmState::Destroyed);
    Queue::assertNotPushed(CancelProofGroup::class);
})->with([false, true]);

it('releases a proof claim after a definite Swamp start refusal', function (): void {
    Queue::fake();
    [$proof, [[$run]]] = proofRuns(['firewall:allow']);
    $run->update(['status' => OperationStatus::Failed, 'error_code' => 'swamp.start_refused']);
    app(ProofCommandProjection::class)->refused($run);
    Queue::assertPushed(ReleaseTaskVmSlot::class, 1);
    expect($proof->refresh()->status)->toBe(OperationStatus::Failed);
});

/**
 * Swamp serve as the reader of the Gateway sees it: `$parents` maps each nested run to its parent, `$histories` holds each run's history,
 * and `$artifacts` the content of each recorded data artifact by its ID. `reads` counts the history reads.
 */
function proofSwampSocket(array $parents, array $histories, array $artifacts = []): WebSocketClient
{
    return new class($parents, $histories, $artifacts) implements WebSocketClient
    {
        public array $reads = [];

        private ?array $frame = null;

        private bool $open = false;

        public function __construct(private array $parents, private array $histories, private array $artifacts) {}

        public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void
        {
            $this->open = true;
        }

        public function send(array $message): void
        {
            $this->frame = $message;
        }

        public function receive(float $timeoutSeconds): array
        {
            [$frame, $this->frame] = [$this->frame, null];
            if ($frame === null) {
                return [];
            }
            $data = match ($frame['type']) {
                'workflow.run.search' => ['results' => array_map(static fn (string $child, string $parent): array => ['runId' => $child, 'parentRun' => ['runId' => $parent]],
                    array_keys($this->parents), $this->parents)],
                'data.query' => ['results' => [], 'projected' => ['shape' => 'map', 'columns' => ['id', 'name', 'version', 'content'],
                    'rows' => array_values(array_filter(array_map(static fn (string $id, array $content): array => ['id' => $id, 'name' => 'result', 'version' => 1, 'content' => $content],
                        array_keys($this->artifacts), $this->artifacts), static fn (array $row): bool => $frame['payload']['predicate'] === 'id == "'.$row['id'].'" && version == 1'))]],
                default => $this->histories[$this->reads[] = $frame['payload']['workflowIdOrName']],
            };

            return [['id' => $frame['id'], 'type' => $frame['type'], 'payload' => ['data' => $data]]];
        }

        public function close(): void
        {
            $this->open = false;
        }

        public function isConnected(): bool
        {
            return $this->open;
        }
    };
}

/** Runs `$read` against the fake serve `$socket`. */
function withProofSwamp(WebSocketClient $socket, Closure $read): void
{
    app()->instance(WebSocketClient::class, $socket);
    app(SwampConnection::class)->release();
    config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/unused-test-ca', 'swamp.read_token' => 'fixture-read-token']);
    try {
        $read();
    } finally {
        app(SwampConnection::class)->release();
    }
}

describe('a run that ends at its budget', function (): void {
    /** The report of a batch child, as `proof-fleet-verify` ends with it and the reader captures it. */
    $child = static fn (string $batch, string $lines): array => [
        ['intent' => ['command' => 'firewall:allow', 'file' => 'swamp/proof/firewall/allow.cases.yaml', 'batch' => $batch]], ['report' => $lines],
    ];

    it('records the full report of each assigned batch once, while the run goes on, and reads no other ended run again', function (): void {
        [, [[$run]]] = proofRuns(['firewall:allow']);
        $file = 'swamp/proof/firewall/allow.cases.yaml';
        $run->update(['input' => [...$run->input, 'assignments' => [['command' => 'firewall:allow', 'file' => $file, 'batch' => 'one', 'cases' => ['main']]]]]);
        $batch = '6b69badf-4f06-4f2e-82d7-21b62c1f5871';
        $inner = '6b69badf-4f06-4f2e-82d7-21b62c1f5872';
        // More than the 4 KiB that the run history keeps of a step's output: the reader takes the step's result artifact.
        $report = PROOF_PASSED."\n".str_repeat('x', 5000);
        $histories = [
            PROOF_RUN_ID => ['id' => PROOF_RUN_ID, 'workflowName' => 'operation-proof-run', 'status' => 'running', 'startedAt' => now()->subMinutes(5)->toISOString(),
                'inputs' => ['operation_id' => $run->id, 'node_id' => $run->node_id, 'kind' => 'proof.command'], 'jobs' => []],
            $batch => ['id' => $batch, 'workflowName' => 'proof-fleet-verify', 'status' => 'succeeded', 'completedAt' => now()->toISOString(),
                'inputs' => ['intent' => ['command' => 'firewall:allow', 'file' => $file, 'batch' => 'one']],
                'jobs' => [['name' => 'report', 'steps' => [['name' => 'report', 'status' => 'succeeded', 'outputs' => [],
                    'dataArtifacts' => [['dataId' => '9bbf19aa-d4a5-405e-9a43-901fb585a813', 'name' => 'result', 'version' => 1]]]]]]],
            $inner => ['id' => $inner, 'workflowName' => 'proof-fleet-verify-batch', 'status' => 'succeeded', 'completedAt' => now()->toISOString(), 'inputs' => [], 'jobs' => []],
        ];
        $socket = proofSwampSocket([$batch => PROOF_RUN_ID, $inner => $batch], $histories, ['9bbf19aa-d4a5-405e-9a43-901fb585a813' => ['stdout' => $report]]);

        withProofSwamp($socket, function () use ($run): void {
            $selector = app(DirectOperation::class)->definition('proof.command')['terminal_result'];
            expect(fn () => app(SwampCommandStep::class)->projectTerminal($run, ['id' => PROOF_RUN_ID], $selector))->toThrow(SwampTerminalReadUnavailable::class);
            expect(fn () => app(SwampCommandStep::class)->projectTerminal($run->refresh(), ['id' => PROOF_RUN_ID], $selector))->toThrow(SwampTerminalReadUnavailable::class);
        });

        expect($run->refresh()->state[ProofBatchProjection::BatchReports])->toBe(["firewall:allow\t{$file}\tone" => $report])
            ->and(array_keys($run->state['native_children']))->toEqualCanonicalizing([$batch, $inner])
            ->and($run->status)->toBe(OperationStatus::Running)
            // Each pass reads the root; the two nested runs are read once.
            ->and(array_count_values($socket->reads))->toBe([PROOF_RUN_ID => 2, $batch => 1, $inner => 1]);
    });

    it('records the report of each assigned batch once', function () use ($child): void {
        [, [[$run]]] = proofRuns(['firewall:allow']);
        $file = 'swamp/proof/firewall/allow.cases.yaml';
        $run->update(['input' => [...$run->input, 'assignments' => [['command' => 'firewall:allow', 'file' => $file, 'batch' => 'one', 'cases' => ['main']]]]]);
        $projection = app(ProofBatchProjection::class);

        expect($projection->acceptsParent($run))->toBeTrue()
            ->and($projection->acceptsParent(new Operation(['kind' => 'proof.verify'])))->toBeFalse();
        $projection->applyChild($run, ...$child('one', PROOF_PASSED));
        $projection->applyChild($run, ...$child('one', PROOF_FAILED));
        $projection->applyChild($run, ...$child('other', PROOF_PASSED));

        expect($run->refresh()->state[ProofBatchProjection::BatchReports])->toBe(["firewall:allow\t{$file}\tone" => PROOF_PASSED]);
    });

    it('keeps the verdicts of the batches that ended, and only the cases of the others are unrun', function () use ($child): void {
        Queue::fake();
        [$proof, [[$run]]] = proofRuns(['firewall:allow']);
        $file = 'swamp/proof/firewall/allow.cases.yaml';
        $batches = [['command' => 'firewall:allow', 'file' => $file, 'batch' => 'one', 'cases' => ['main']],
            ['command' => 'firewall:allow', 'file' => $file, 'batch' => 'two', 'cases' => ['deny']]];
        $run->forceFill(['input' => [...$run->input, 'ci_pool' => true, 'assignments' => $batches, 'run_budget_seconds' => 600], 'started_at' => now()->subSeconds(2401)])->save();
        $proof->update(['input' => [...$proof->input, 'selected_commands' => ['firewall:allow'], 'batches' => $batches]]);
        app(ProofBatchProjection::class)->applyChild($run, ...$child('one', PROOF_PASSED));

        NativeRunBudgetSocket::install($run);
        app(ExpireOperationAction::class)->execute($run->refresh());

        $verdicts = collect($proof->refresh()->result['cases'])->mapWithKeys(static fn (array $case): array => [$case['case'] => $case['verdict']])->all();
        expect($run->refresh()->error_code)->toBe('operation.timed_out')
            ->and($proof->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('proof.cases_failed')
            ->and($verdicts)->toBe(['main' => 'passed', 'deny' => 'unrun']);
        Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $run->id);
    });
});

it('keeps the verdicts of a cancelled orbit proof', function (): void {
    [, [[$run, $group]]] = proofRuns(['firewall:allow']);
    $verify = Operation::query()->create(['kind' => 'proof.verify', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $run->node_id,
        'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => PROOF_RUN_ID],
        'input' => ['command' => 'firewall:allow', 'file' => 'swamp/proof/firewall/allow.cases.yaml', 'execution_secrets' => []]]);
    $histories = [PROOF_RUN_ID => ['id' => PROOF_RUN_ID, 'workflowName' => 'proof-fleet-verify', 'status' => 'cancelled', 'startedAt' => now()->subMinutes(5)->toISOString(),
        'completedAt' => now()->toISOString(), 'inputs' => ['operation_id' => $verify->id, 'node_id' => $verify->node_id, 'kind' => 'proof.verify'],
        'jobs' => [['name' => 'report', 'steps' => [['name' => 'report', 'status' => 'succeeded', 'outputs' => ['stdout' => PROOF_PASSED."\n".PROOF_FAILED]]]]]]];

    withProofSwamp(proofSwampSocket([], $histories), function () use ($verify): void {
        app(SwampCommandStep::class)->projectTerminal($verify, ['id' => PROOF_RUN_ID], app(DirectOperation::class)->definition('proof.verify')['terminal_result']);
    });

    expect($verify->refresh()->status)->toBe(OperationStatus::Cancelled)
        ->and(array_column($verify->result['cases'], 'verdict'))->toBe(['passed', 'failed']);
});

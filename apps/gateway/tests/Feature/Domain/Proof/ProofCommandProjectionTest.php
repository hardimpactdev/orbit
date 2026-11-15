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
use App\Domain\Tasks\TaskGroupStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampConnection;
use App\Infrastructure\Swamp\SwampTerminalReadUnavailable;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Jobs\Proof\CancelProofGroup;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

uses(LazilyRefreshDatabase::class);

const PROOF_RUN_ID = '6b69badf-4f06-4f2e-82d7-21b62c1f587a';

/** The `proof.run` of the CLI with `$commands` runs, each as the Gateway records it at admission: a run in the copy its group owns. */
function proofRuns(array $commands): array
{
    $host = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'beast', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.11', 'public_ssh_port' => 22,
        'wireguard_ip' => '10.44.0.11', 'user' => 'orbit', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $host->forceFill(['instance_bridge' => '10.253.0.0/24'])->saveQuietly();
    $project = Project::query()->create(['name' => 'orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/hardimpactdev/orbit.git', 'default_branch' => 'main', 'root' => 'public']);
    $proof = Operation::query()->create(['kind' => 'proof.run', 'target_type' => 'node', 'target_id' => $host->id, 'node_id' => $host->id, 'status' => OperationStatus::Running,
        'input' => ['commit' => str_repeat('a', 40), 'uncovered' => [], 'runs' => []]]);
    $runs = [];
    $records = [];
    foreach ($commands as $index => $command) {
        $group = Task::query()->create(['project_id' => $project->id, 'title' => 'Proof '.$command, 'brief' => 'Brief', 'status' => TaskGroupStatus::Reserved]);
        $copy = Instance::query()->create(['project_id' => $project->id, 'node_id' => $host->id, 'name' => 'proof-copy-'.$index, 'source_layout' => InstanceSourceLayout::Checkout,
            'source_prepare_id' => 'prepare-'.$index, 'checkout_path' => '/srv/proof-copy-'.$index, 'task_workspace_owner_id' => $group->id,
            'status' => InstanceState::Active, 'first_setup_pending' => false]);
        InstanceRuntime::query()->create(['instance_id' => $copy->id, 'node_id' => $host->id, 'incus_project' => 'nested', 'container_name' => 'proof-copy-'.$index,
            'bridge_address' => '10.253.0.'.(10 + $index), 'runtime_version' => 'fixture', 'agent_version' => 'fixture', 'power_state' => 'running']);
        $group->taskable()->associate($copy)->save();
        $file = 'swamp/proof/'.str_replace(':', '/', $command).'.cases.yaml';
        $run = Operation::query()->create(['kind' => 'proof.command', 'target_type' => 'task_group', 'target_id' => $group->id, 'node_id' => $host->id, 'status' => OperationStatus::Running,
            'state' => ['swamp_run_id' => PROOF_RUN_ID], 'input' => [
                'proof_operation_id' => $proof->id, 'commit' => str_repeat('a', 40), 'command' => $command, 'file' => $file, 'copy' => true,
                'group_id' => $group->id, 'instance_id' => $copy->id, 'node_id' => $host->id,
            ]]);
        $runs[] = ['command' => $command, 'file' => $file, 'group_id' => $group->id, 'instance_id' => $copy->id, 'operation_id' => $run->id];
        $records[] = [$run, $group];
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

describe('the record of one run', function (): void {
    it('fails the run, with one unrun row, when the commit never reached the copy, and cancels the group, which removes the copy', function (): void {
        Queue::fake();
        [$proof, [[$run]]] = proofRuns(['firewall:allow']);

        $projected = app(ProofCommandProjection::class)->apply($run, proofEnded('failed', ['Bring in the commit' => ['status' => 'failed']]));

        expect($projected->status)->toBe(OperationStatus::Failed)
            ->and($projected->error_code)->toBe('proof.run_failed')
            ->and($projected->result['cases'])->toHaveCount(1)
            ->and($projected->result['cases'][0])->toMatchArray(['program' => 'swamp/proof/firewall/allow.cases.yaml', 'command' => 'firewall:allow', 'verdict' => 'unrun', 'seconds' => 0]);
        Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $run->id);
        expect($proof->refresh()->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('proof.run_failed');
    });

    it('records a failed case as proof.cases_failed, with the rows of every case', function (): void {
        Queue::fake();
        [$proof, [[$run]]] = proofRuns(['firewall:allow']);

        $projected = app(ProofCommandProjection::class)->apply($run, proofEnded('failed', ['Bring in the commit' => ['status' => 'succeeded'], 'Prove assigned batches' => ['status' => 'succeeded']], PROOF_PASSED."\n".PROOF_FAILED));

        expect($projected->error_code)->toBe('proof.cases_failed')
            ->and(array_column($projected->result['cases'], 'verdict'))->toBe(['passed', 'failed'])
            ->and($projected->result['cases'][1])->toMatchArray(['program' => 'swamp/proof/firewall/allow.cases.yaml#deny', 'command' => 'firewall:allow', 'case' => 'deny', 'cause' => 'assertion', 'failed' => ['ufw']])
            ->and($proof->refresh()->error_code)->toBe('proof.cases_failed');
    });

    it('records a run once', function (): void {
        Queue::fake();
        [, [[$run]]] = proofRuns(['firewall:allow']);
        $ended = proofEnded('failed', ['Bring in the commit' => ['status' => 'failed']]);

        $first = app(ProofCommandProjection::class)->apply($run, $ended);
        $again = app(ProofCommandProjection::class)->apply($run->refresh(), $ended);

        expect($again->sequence)->toBe($first->sequence);
    });
});

describe('native proof interruption', function (): void {
    it('preserves interrupted status and every verdict while settling the proof without passing', function (string $report, array $verdicts, OperationStatus $aggregate): void {
        Queue::fake();
        [$proof, [[$run]]] = proofRuns(['firewall:allow']);
        $batch = ['command' => 'firewall:allow', 'file' => $run->input['file'], 'batch' => '', 'cases' => ['main', 'deny']];
        $run->update(['input' => [...$run->input, 'assignments' => [$batch]]]);
        $proof->update(['input' => [...$proof->input, 'selected_commands' => ['firewall:allow'], 'batches' => [$batch]]]);
        $ended = proofEnded('interrupted', ['Bring in the commit' => ['status' => 'succeeded'], 'Prove assigned batches' => ['status' => 'succeeded']], $report);
        $ended['error_code'] = 'operation.native_failed';

        $projected = app(ProofCommandProjection::class)->apply($run, $ended);

        expect($projected->status)->toBe(OperationStatus::Interrupted)
            ->and($projected->error_code)->toBe('operation.native_failed')
            ->and($projected->result['outcome'])->toBe('interrupted')
            ->and(array_column($projected->result['cases'], 'verdict'))->toBe($verdicts);
        expect($proof->refresh()->status)->toBe($aggregate)
            ->and($proof->result['outcome'])->toBe($aggregate === OperationStatus::Failed ? 'failed' : 'interrupted')
            ->and($proof->error_code)->toBe($aggregate === OperationStatus::Failed ? 'proof.cases_failed' : 'proof.run_interrupted')
            ->and($proof->result['cases'])->toBe($projected->result['cases']);
        Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $run->id);

        $sequence = $projected->sequence;
        $parentSequence = $proof->sequence;
        // A terminal replay cannot replace the saved interruption with late success or lose its case evidence.
        $ended['status'] = 'succeeded';
        $ended['result']['report'] = '';
        $again = app(ProofCommandProjection::class)->apply($run->refresh(), $ended);
        app(ProofRunSettlement::class)->settle($proof->id);
        expect($again->sequence)->toBe($sequence)->and($again->result)->toBe($projected->result);
        expect($proof->refresh()->sequence)->toBe($parentSequence);
    })->with([
        'before cases' => ['', ['unrun', 'unrun'], OperationStatus::Interrupted],
        'partial coverage' => [PROOF_PASSED, ['passed', 'unrun'], OperationStatus::Interrupted],
        'all passed rows' => [PROOF_PASSED."\n".'{"id":"deny","verdict":"passed","secs":1}', ['passed', 'passed'], OperationStatus::Interrupted],
        'completed failure' => [PROOF_PASSED."\n".PROOF_FAILED, ['passed', 'failed'], OperationStatus::Failed],
        'malformed coverage' => [PROOF_PASSED."\n".'not a verdict', ['passed', 'unrun', 'unrun'], OperationStatus::Interrupted],
    ]);

    it('keeps recorded batch failures with absent, partial or complete interrupted reports', function (string $finalReport): void {
        Queue::fake();
        [$proof, [[$run]]] = proofRuns(['firewall:allow']);
        $file = $run->input['file'];
        $batches = [['command' => 'firewall:allow', 'file' => $file, 'batch' => 'one', 'cases' => ['deny']],
            ['command' => 'firewall:allow', 'file' => $file, 'batch' => 'two', 'cases' => ['main']]];
        $run->update(['input' => [...$run->input, 'assignments' => $batches]]);
        $proof->update(['input' => [...$proof->input, 'batches' => $batches, 'selected_commands' => ['firewall:allow']]]);
        app(ProofBatchProjection::class)->applyChild($run, ['intent' => $batches[0]], ['report' => PROOF_FAILED]);
        $report = match ($finalReport) {
            'complete' => "firewall:allow\t{$file}\tone\t".PROOF_FAILED."\nfirewall:allow\t{$file}\ttwo\t".PROOF_PASSED,
            'partial' => "firewall:allow\t{$file}\ttwo\t".PROOF_PASSED,
            default => '',
        };
        $history = ['id' => PROOF_RUN_ID, 'workflowName' => 'operation-proof-run-copy', 'status' => 'interrupted',
            'startedAt' => now()->subMinute()->toISOString(), 'completedAt' => now()->toISOString(),
            'inputs' => ['operation_id' => $run->id, 'node_id' => $run->node_id, 'kind' => 'proof.command'],
            'jobs' => $finalReport === 'absent' ? [] : [['name' => 'prove', 'steps' => [['name' => 'Report', 'status' => 'succeeded', 'outputs' => ['stdout' => $report]]]]]];

        withProofSwamp(proofSwampSocket([], [PROOF_RUN_ID => $history]), function () use ($run): void {
            app(SwampCommandStep::class)->projectTerminal($run, ['id' => PROOF_RUN_ID], app(DirectOperation::class)->definition('proof.command')['terminal_result']);
        });

        expect($run->refresh()->status)->toBe(OperationStatus::Interrupted)->and($run->result['outcome'])->toBe('interrupted')
            ->and(array_column($run->result['cases'], 'verdict'))->toBe(['failed', $finalReport === 'absent' ? 'unrun' : 'passed'])
            ->and($run->result['cases'][0])->toMatchArray(['case' => 'deny', 'cause' => 'assertion', 'failed' => ['ufw']]);
        expect($proof->refresh()->status)->toBe(OperationStatus::Failed)->and($proof->result['outcome'])->toBe('failed')
            ->and($proof->error_code)->toBe('proof.cases_failed');
        Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $run->id);
    })->with(['absent', 'partial', 'complete']);

    it('settles completed interrupted proof evidence through only the reconcile job', function (bool $batchOnly): void {
        Queue::fake();
        [$proof, [[$run]]] = proofRuns(['firewall:allow']);
        RateLimiter::clear('swamp-read');
        RateLimiter::clear('swamp-read:operation:'.$run->id);
        $file = $run->input['file'];
        $assignment = ['command' => 'firewall:allow', 'file' => $file, 'batch' => 'one', 'cases' => ['deny']];
        $run->update(['input' => [...$run->input, 'assignments' => [$assignment]]]);
        $proof->update(['input' => [...$proof->input, 'batches' => [$assignment], 'selected_commands' => ['firewall:allow']]]);
        $batch = '6b69badf-4f06-4f2e-82d7-21b62c1f5871';
        $history = ['id' => PROOF_RUN_ID, 'workflowName' => 'operation-proof-run-copy', 'status' => 'interrupted',
            'startedAt' => now()->subMinute()->toISOString(), 'completedAt' => now()->toISOString(),
            'inputs' => ['operation_id' => $run->id, 'node_id' => $run->node_id, 'kind' => 'proof.command'],
            'jobs' => $batchOnly ? [] : [['name' => 'prove', 'steps' => [['name' => 'Report', 'status' => 'succeeded',
                'outputs' => ['stdout' => "firewall:allow\t{$file}\tone\t".PROOF_FAILED]]]]]];
        $histories = [PROOF_RUN_ID => $history];
        if ($batchOnly) {
            $histories[$batch] = ['id' => $batch, 'workflowName' => 'proof-fleet-verify', 'status' => 'failed',
                'completedAt' => now()->toISOString(), 'inputs' => ['intent' => $assignment],
                'jobs' => [['name' => 'report', 'steps' => [['name' => 'report', 'status' => 'succeeded', 'outputs' => ['stdout' => PROOF_FAILED]]]]]];
        }
        expect($run->state[ProofBatchProjection::BatchReports] ?? [])->toBe([]);
        withProofSwamp(proofSwampSocket($batchOnly ? [$batch => PROOF_RUN_ID] : [], $histories), function (): void {
            app()->call([new ReconcileSwampOperations, 'handle']);
        });
        expect($run->refresh()->status)->toBe(OperationStatus::Interrupted)
            ->and($run->result['outcome'])->toBe('interrupted')
            ->and($run->result['cases'][0])->toMatchArray(['case' => 'deny', 'verdict' => 'failed', 'cause' => 'assertion', 'failed' => ['ufw']]);
        expect($proof->refresh()->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('proof.cases_failed');
    })->with([false, true]);

    it('waits for live children and gives a completed non-interrupted failure precedence in either order', function (OperationStatus $other, string $verdict, OperationStatus $aggregate, bool $interruptFirst): void {
        [$proof, [[$first], [$second]]] = proofRuns(['firewall:allow', 'firewall:deny']);
        $interrupted = $interruptFirst ? $first : $second;
        $finished = $interruptFirst ? $second : $first;
        $interrupted->update(['status' => OperationStatus::Interrupted, 'result' => ['cases' => [['program' => 'p', 'command' => $interrupted->input['command'], 'verdict' => 'unrun', 'seconds' => 0]]]]);
        app(ProofRunSettlement::class)->settle($proof->id);
        expect($proof->refresh()->status)->toBe(OperationStatus::Running);

        $finished->update(['status' => $other, 'result' => ['cases' => [['program' => 'p', 'command' => $finished->input['command'], 'verdict' => $verdict, 'seconds' => 1]]]]);
        app(ProofRunSettlement::class)->settle($proof->id);

        expect($proof->refresh()->status)->toBe($aggregate)
            ->and($proof->result['outcome'])->toBe($aggregate === OperationStatus::Interrupted ? 'interrupted' : 'failed')
            ->and(array_column($proof->result['cases'], 'verdict'))->toBe($interruptFirst ? ['unrun', $verdict] : [$verdict, 'unrun']);
    })->with([
        'passed sibling' => [OperationStatus::Succeeded, 'passed', OperationStatus::Interrupted],
        'failed case' => [OperationStatus::Failed, 'failed', OperationStatus::Failed],
        'failed setup' => [OperationStatus::Failed, 'unrun', OperationStatus::Failed],
        'failed cleanup after passed cases' => [OperationStatus::Failed, 'passed', OperationStatus::Failed],
        'cancelled sibling' => [OperationStatus::Cancelled, 'unrun', OperationStatus::Failed],
        'invalid succeeded sibling' => [OperationStatus::Succeeded, 'unrun', OperationStatus::Failed],
    ])->with([true, false]);

    it('keeps missing admitted coverage unrun when the native run is interrupted', function (string $missing): void {
        [$proof, [[$run]]] = proofRuns(['firewall:allow']);
        $run->update(['status' => OperationStatus::Interrupted, 'result' => ['cases' => [['program' => $run->input['file'].'#main', 'command' => 'firewall:allow', 'case' => 'main', 'verdict' => 'passed', 'seconds' => 1]]]]);
        $proof->update(['input' => [...$proof->input, 'selected_commands' => ['firewall:allow', 'firewall:deny'],
            'batches' => [['command' => 'firewall:deny', 'file' => 'swamp/proof/firewall/deny.cases.yaml', 'batch' => '', 'cases' => ['missing']]],
            'runs' => $missing === 'shard' ? [...$proof->input['runs'], ['operation_id' => $run->id + 100]] : $proof->input['runs']]]);

        app(ProofRunSettlement::class)->settle($proof->id);

        expect($proof->refresh()->status)->toBe(OperationStatus::Interrupted)->and($proof->result['outcome'])->toBe('interrupted')
            ->and($proof->result['cases'][1])->toMatchArray(['command' => 'firewall:deny', 'case' => 'missing', 'verdict' => 'unrun']);
    })->with(['case', 'shard']);
});

describe('the proof of the CLI', function (): void {
    it('stays running until the last run has ended', function (): void {
        Queue::fake();
        [$proof, [[$first], [$second]]] = proofRuns(['firewall:allow', 'firewall:deny']);

        app(ProofCommandProjection::class)->apply($first, proofEnded('failed', ['Bring in the commit' => ['status' => 'failed']]));
        expect($proof->refresh()->status)->toBe(OperationStatus::Running);

        app(ProofCommandProjection::class)->apply($second, proofEnded('failed', ['Bring in the commit' => ['status' => 'failed']]));
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

describe('the check of the workspace commit', function (): void {
    /** The steps of a run whose copy failed the check of its workspace, so that no case ran. */
    function proofWorkspaceSteps(string $printed): array
    {
        return ['Bring in the commit' => ['status' => 'succeeded'], 'Read the workspace commit' => ['status' => 'succeeded', 'stdout' => $printed],
            'Check the workspace commit' => ['status' => 'failed'], 'Prove assigned batches' => ['status' => 'skipped']];
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

    it('records no commit when the copy printed none that looks like one, so no text of the copy reaches the result', function (string $printed): void {
        Queue::fake();
        [, [[$run]]] = proofRuns(['firewall:allow']);

        $projected = app(ProofCommandProjection::class)->apply($run, proofEnded('failed', proofWorkspaceSteps($printed)));

        expect($projected->error_code)->toBe('proof.workspace_commit_mismatch')->and($projected->result['workspace'])->toBe(['expected' => str_repeat('a', 40), 'found' => '']);
    })->with(['nothing' => [''], 'an error' => ["fatal: not a git repository\n"], 'a short id' => ['abc123']]);

    it('leaves the code to the cases when the check passed', function (): void {
        Queue::fake();
        [, [[$run]]] = proofRuns(['firewall:allow']);
        $steps = [...proofWorkspaceSteps(str_repeat('a', 40)), 'Check the workspace commit' => ['status' => 'succeeded'], 'Prove assigned batches' => ['status' => 'succeeded']];

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

describe('the check of the workspace commit, with the end of the copy', function (): void {
    it('cancels the group of a run that failed the check, which removes its copy', function (): void {
        Queue::fake();
        [, [[$run]]] = proofRuns(['firewall:allow']);
        $ended = proofEnded('failed', proofWorkspaceSteps(str_repeat('b', 40)));

        $projected = app(ProofCommandProjection::class)->apply($run, $ended);

        expect($projected->error_code)->toBe('proof.workspace_commit_mismatch');
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

it('merges full shard reports beyond the history tail, requires every case, and removes each copy', function (bool $omit): void {
    Queue::fake();
    [$proof, [[$first], [$second]]] = proofRuns(['firewall:allow', 'firewall:deny']);
    $batches = [];
    foreach ([$first, $second] as $index => $run) {
        $ids = array_map(static fn (int $number): string => 'case-'.$number, range(1, 120));
        $batch = ['command' => $run->input['command'], 'file' => $run->input['file'], 'batch' => 'fresh', 'cases' => $ids];
        $batches[] = $batch;
        $run->update(['input' => [...$run->input, 'ci_pool' => true, 'assignments' => [$batch]]]);
        $ended = proofEnded('succeeded', ['Bring in the commit' => ['status' => 'succeeded'], 'Prove assigned batches' => ['status' => 'succeeded']]);
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
        ->and(array_unique(array_column($proof->result['cases'], 'reason')))->toBe(['uses changed fixture']);
    Queue::assertPushed(CancelProofGroup::class, 2);
})->with([false, true]);

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
            PROOF_RUN_ID => ['id' => PROOF_RUN_ID, 'workflowName' => 'operation-proof-run-copy', 'status' => 'running', 'startedAt' => now()->subMinutes(5)->toISOString(),
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
        $run->forceFill(['input' => [...$run->input, 'ci_pool' => true, 'assignments' => $batches, 'run_budget_seconds' => 600], 'started_at' => now()->subSeconds(601)])->save();
        $proof->update(['input' => [...$proof->input, 'selected_commands' => ['firewall:allow'], 'batches' => $batches]]);
        app(ProofBatchProjection::class)->applyChild($run, ...$child('one', PROOF_PASSED));

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

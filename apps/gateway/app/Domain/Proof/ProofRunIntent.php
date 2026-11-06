<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Actions\Tasks\CreateTaskGroupAction;
use App\Data\Tasks\CreateTaskGroupData;
use App\Data\Tasks\TaskInputData;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCancelIntent;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskProvisionIntent;
use App\Domain\TaskVms\TaskVmModelAdmission;
use App\Infrastructure\Swamp\PendingSshHostKey;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Admits `proof:run`: it checks that the commit is trusted, selects the cases from the diff and the selection list of the commit,
 * and records the intent of one run for each selected command, each on a task group and VM of its own, all or none. The Gateway
 * reads GitHub and fetches no code; the Swamp parent `operation-proof-run` of each run does the work. See docs/cli/proof/run.mmd.
 */
final readonly class ProofRunIntent
{
    public function __construct(private ProofCommitTrust $trust, private ProofCommitSource $source, private ProofSettings $settings,
        private OperationIdempotency $idempotency, private OperationStore $store, private ProofGroup $groups) {}

    public function start(string $commit, bool $all, string $caller, ?string $requestId): Operation
    {
        if (($existing = $this->idempotency->replay(OperationKinds::ProofRun)) !== null) {
            return $existing;
        }
        $this->trust->assert($commit);
        $selection = $this->select($commit, $all);
        $swamp = Node::query()->where('status', LifecycleStatus::Active)->whereHas('roles', static fn ($roles) => $roles
            ->where('role', RoleName::Swamp)->where('status', LifecycleStatus::Active))->first()
            ?? throw new ResourceOperationException('proof.swamp_unavailable', 'No active Swamp Node can run a proof.', 503);
        $intent = ['commit' => $commit, 'all' => $all, 'everything_because' => $selection->everythingBecause,
            'cases' => $selection->cases, 'uncovered' => $selection->uncovered];
        if ($selection->nothingToProve()) {
            $operation = $this->store->create(OperationKinds::ProofRun, 'node', $swamp->id, $swamp->id, $intent, $caller, $requestId);
            $this->store->change($operation, ['status' => OperationStatus::Succeeded, 'started_at' => now(), 'finished_at' => now(), 'result' => [
                'commit' => $commit, 'outcome' => 'nothing_to_prove', 'reason' => 'proof.nothing_to_prove', 'cases' => [],
                'uncovered' => $selection->uncovered, 'receipt_url' => null,
            ]]);

            return $operation;
        }
        if (! $this->settings->executionEnabled()) {
            throw new ResourceOperationException('proof.execution_disabled', 'Proof execution is switched off: the commit is trusted and the cases are selected, but nothing runs.', 409,
                details: ['commit' => $commit, 'cases' => $selection->files(), 'uncovered' => array_column($selection->uncovered, 'path')]);
        }

        // The proof the CLI follows closes once, when the last of its runs has ended. Each run is an Operation of its own on its own task group.
        $proof = $this->store->create(OperationKinds::ProofRun, 'node', $swamp->id, $swamp->id, [...$intent, 'runs' => []], $caller, $requestId);
        $this->store->change($proof, ['status' => OperationStatus::Running, 'started_at' => now()]);
        try {
            // One transaction holds every group, VM and run, so a command that cannot start (no host has room) leaves nothing behind:
            // the groups roll back with it, and Swamp starts no run before the whole proof is admitted. The router fence spans the
            // transaction, so a router teardown never sees a half-admitted proof.
            $runs = app(TaskVmModelAdmission::class)->run(fn (): array => DB::transaction(function () use ($proof, $commit, $selection): array {
                $runs = [];
                foreach ($selection->cases as $case) {
                    $group = $this->group($commit, $case['command']);
                    $run = $this->groups->admit($group, 'proof.command', fn (): Operation => app(TaskProvisionIntent::class)->admit($group, $commit, 'proof.command',
                        fn (array $input, Task $group, Instance $instance, TaskVm $vm): array => $this->runInput($proof, $commit, $selection->mergeBase, $case, $input, $group, $instance), 'proof-run', null));
                    $runs[] = ['command' => $case['command'], 'file' => $case['file'], 'group_id' => $group->id, 'operation_id' => $run->id];
                }

                return $runs;
            }));
        } catch (Throwable $exception) {
            $this->store->change($proof, ['status' => OperationStatus::Failed, 'finished_at' => now(),
                'error_code' => $exception instanceof ResourceOperationException ? $exception->errorCode : ProofCommandProjection::RunFailed,
                'result' => ['commit' => $commit, 'outcome' => 'failed', 'cases' => [], 'uncovered' => $selection->uncovered, 'receipt_url' => null]]);

            throw $exception;
        }
        $this->store->change($proof, ['input' => [...$intent, 'runs' => $runs]]);

        return $proof;
    }

    /**
     * The full input of one run: the provision as admitted, the cases of one command for the VM it makes, and the destruction of that VM.
     * Captured in the admission transaction of the provision, so that no step of the run calls the Gateway.
     *
     * @param  array{command:string,file:string}  $case
     * @param  array<string,mixed>  $input  the provision input
     * @return array<string,mixed>
     */
    private function runInput(Operation $proof, string $commit, string $base, array $case, array $input, Task $group, Instance $instance): array
    {
        $instance->loadMissing('node');
        // The guest's host key is pinned only by the VM step of the run; the run fills it into this target.
        $ssh = app(PendingSshHostKey::class)->during($instance->node_id, fn (): array => app(SwampSshExecutionInput::class)->forNode($instance->node));

        return [
            'proof_operation_id' => $proof->id, 'commit' => $commit, 'command' => $case['command'], 'file' => $case['file'],
            'group_id' => $group->id, 'instance_id' => $instance->id, 'node_id' => $input['node_id'],
            'execution_secrets' => $input['execution_secrets'] ?? [],
            'provision' => array_diff_key($input, ['execution_secrets' => true]),
            'verify' => ['group_id' => $group->id, 'instance_id' => $instance->id, 'node_id' => $instance->node_id, 'command' => $case['command'],
                'file' => $case['file'], 'ssh_execution' => $ssh, 'checkout_path' => $instance->checkout_path,
                // The proof of a new case switches the checkout to the merge base, with the new cases file of the head.
                'head' => $commit, 'merge_base' => $base],
            'cancel' => app(TaskCancelIntent::class)->captureForProof($group),
        ];
    }

    private function group(string $commit, string $command): Task
    {
        return app(CreateTaskGroupAction::class)->execute(new CreateTaskGroupData($this->settings->projectId(), 'Proof '.$command.' '.substr($commit, 0, 12),
            'Prove '.$command.' at commit '.$commit.'.', TaskGroupStatus::Todo, [new TaskInputData('Run the proof cases',
                'The Swamp workflow operation-proof-run proves the command in its own task VM.', [['id' => 'verdict', 'type' => 'review', 'description' => 'Every selected case passes.']])]));
    }

    private function select(string $commit, bool $all): ProofSelectionResult
    {
        try {
            $changes = $this->source->changes($commit);
            $list = $this->source->file($commit, ProofSelectionList::Path);
            $casesFiles = ProofSelection::casesFiles($this->source->proofPaths($commit));
        } catch (GitHubApiException) {
            throw new ResourceOperationException('proof.github_unavailable', 'GitHub could not be read, so the cases cannot be selected.', 503);
        }

        return ProofSelection::select($list, $changes, $all, $casesFiles);
    }
}

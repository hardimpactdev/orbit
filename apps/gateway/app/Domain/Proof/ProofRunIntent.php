<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Actions\Operations\StartOperationAction;
use App\Actions\Tasks\CancelTaskGroupAction;
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
use App\Infrastructure\Swamp\PendingSshHostKey;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Task;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Admits `proof:run`: it checks that the commit is trusted, selects the cases from the diff and the selection list of
 * the commit, and records the intent. The Gateway reads GitHub and fetches no code; the one Swamp run `operation-proof-run` does the work and the Gateway records its result once. See
 * docs/cli/proof/run.mmd.
 */
final readonly class ProofRunIntent
{
    public function __construct(private ProofCommitTrust $trust, private ProofCommitSource $source, private ProofSettings $settings,
        private OperationIdempotency $idempotency, private OperationStore $store, private StartOperationAction $start) {}

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
        if (count($this->commands($selection->cases)) > ProofRunProjection::MaximumCommands) {
            throw new ResourceOperationException('proof.too_many_commands', 'A proof run covers at most '.ProofRunProjection::MaximumCommands.' commands.', 422);
        }
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
                details: ['commit' => $commit, 'cases' => $selection->programs(), 'uncovered' => array_column($selection->uncovered, 'path')]);
        }
        $group = app(CreateTaskGroupAction::class)->execute(new CreateTaskGroupData($this->settings->projectId(), 'Proof '.substr($commit, 0, 12),
            'Prove commit '.$commit.' with the selected cases.', TaskGroupStatus::Todo, [new TaskInputData('Run the proof cases',
                'The Swamp workflow operation-proof-run runs the selected cases in the task VM.', [['id' => 'verdict', 'type' => 'review', 'description' => 'Every selected case passes.']])]));
        try {
            // The provision takes its own locks, so it commits before the run is admitted.
            $provision = app(TaskProvisionIntent::class)->captureForProof($group, ValidatedData::string($intent['commit']));
        } catch (ResourceOperationException $exception) {
            app(CancelTaskGroupAction::class)->execute($group);

            throw $exception;
        }
        try {
            return DB::transaction(fn (): Operation => $this->admit($group, $swamp, $intent, $selection, $provision, $caller, $requestId));
        } catch (ResourceOperationException $exception) {
            // The group is reserved with its VM: the normal cancel ends it.
            try {
                app(ProofRunCleanup::class)->cancelGroupById($group->id);
            } catch (ResourceOperationException) {
                // The group stays for `tasks:cancel`.
            }

            throw $exception;
        }
    }

    /**
     * Captures the cancel of the VM that the provision recorded, and starts the one Swamp run that
     * carries them. The Operation is not a task_group target: the run cancels the group itself, and a cancel of the group refuses to
     * start while an Operation holds it.
     *
     * @param  array<string,mixed>  $intent
     * @param  array<string,mixed>  $provision
     */
    private function admit(Task $group, Node $swamp, array $intent, ProofSelectionResult $selection, array $provision, string $caller, ?string $requestId): Operation
    {
        $instance = Instance::query()->with('node')->findOrFail(ValidatedData::integer($provision['instance_id']));
        $group->refresh();
        // The guest's host key is read from its VM by the run; until then its SSH target carries `host_keys: null`.
        [$cancel, $ssh] = app(PendingSshHostKey::class)->during($instance->node_id, fn (): array => [
            app(TaskCancelIntent::class)->captureForProof($group), app(SwampSshExecutionInput::class)->forNode($instance->node)]);
        $vm = ValidatedData::object($cancel['provider_input']);
        if (! is_array($vm['vm'] ?? null) || ! is_array($vm['host_ssh_execution'] ?? null)) {
            throw new ResourceOperationException('proof.vm_unavailable', 'A proof needs an Incus task VM, and none was placed.', 503);
        }

        return $this->start->execute(OperationKinds::ProofRun, 'proof', $group->id, $swamp->id, [...$intent, 'group_id' => $group->id,
            'commands' => $this->commands($selection->cases), 'checkout_path' => $instance->checkout_path, 'ssh_execution' => $ssh,
            'vm' => ['name' => $vm['name'], 'project' => ValidatedData::object($vm['vm'])['project'], 'host_ssh_execution' => $vm['host_ssh_execution']],
            'provision' => $provision, 'cancel' => $cancel], $caller, $requestId);
    }

    /**
     * Each command of the selection once, with the cases file that the runner reads. The selection names a command by its case programs.
     *
     * @param  list<array{program: string, command: string}>  $cases
     * @return list<array{command:string,file:string}>
     */
    private function commands(array $cases): array
    {
        $commands = [];
        foreach ($cases as $case) {
            [$family, $name] = explode(':', $case['command'], 2) + [1 => ''];
            $commands[$case['command']] ??= ['command' => $case['command'], 'file' => 'swamp/proof/'.$family.'/'.str_replace(':', '-', $name).'.cases.yaml'];
        }

        return array_values($commands);
    }

    private function select(string $commit, bool $all): ProofSelectionResult
    {
        try {
            $changes = $this->source->changes($commit);
            $list = $this->source->file($commit, ProofSelectionList::Path);
            $programs = ProofSelection::programs($this->source->proofPaths($commit));
        } catch (GitHubApiException) {
            throw new ResourceOperationException('proof.github_unavailable', 'GitHub could not be read, so the cases cannot be selected.', 503);
        }

        return ProofSelection::select($list, $changes, $all, $programs);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Actions\Tasks\CancelTaskGroupAction;
use App\Actions\Tasks\CreateTaskGroupAction;
use App\Actions\Tasks\StartTaskProvisionAction;
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
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\Node;
use App\Models\Operation;

/**
 * Admits `proof:run`: it checks that the commit is trusted, selects the cases from the diff and the selection list of
 * the commit, and records the intent. The Gateway reads GitHub and fetches no code; the task operations of a proof group do the work. See
 * docs/cli/proof/run.mmd.
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
        $group = app(CreateTaskGroupAction::class)->execute(new CreateTaskGroupData($this->settings->projectId(), 'Proof '.substr($commit, 0, 12),
            'Prove commit '.$commit.' with the selected cases.', TaskGroupStatus::Todo, [new TaskInputData('Run the proof cases',
                'The Swamp workflow proof-fleet-verify runs the selected cases in the task VM.', [['id' => 'verdict', 'type' => 'review', 'description' => 'Every selected case passes.']])]));
        try {
            $this->groups->admit($group, 'tasks.provision', fn () => app(StartTaskProvisionAction::class)->execute($group, 'proof-run', null, $commit));
        } catch (ResourceOperationException $exception) {
            app(CancelTaskGroupAction::class)->execute($group);

            throw $exception;
        }
        // The group's own operations carry the run, so this Operation is not a task_group target: the cancel of the group refuses to start while an Operation holds it.
        // It closes with the verdicts after the group is cancelled.
        $operation = $this->store->create(OperationKinds::ProofRun, 'proof', $group->id, $swamp->id, [...$intent, 'group_id' => $group->id], $caller, $requestId);
        $this->store->change($operation, ['status' => OperationStatus::Running, 'started_at' => now()]);

        return $operation;
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

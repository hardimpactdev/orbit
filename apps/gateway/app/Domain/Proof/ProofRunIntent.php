<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Actions\Operations\StartOperationAction;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;
use Throwable;

/**
 * Admits `proof:run`: it checks that the commit is trusted, selects the cases from the diff and the selection list of
 * the commit, and records the intent. The Gateway reads GitHub and fetches no code; Swamp does the work. See
 * docs/cli/proof/run.mmd.
 */
final readonly class ProofRunIntent
{
    public function __construct(private ProofCommitTrust $trust, private ProofCommitSource $source, private ProofSettings $settings,
        private StartOperationAction $start, private OperationIdempotency $idempotency, private ProofSlotClaim $slots, private SwampSshExecutionInput $ssh) {}

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
            return $this->start->record(OperationKinds::ProofRun, 'node', $swamp->id, $swamp->id, $intent, [
                'commit' => $commit, 'outcome' => 'nothing_to_prove', 'reason' => 'proof.nothing_to_prove', 'cases' => [],
                'uncovered' => $selection->uncovered, 'slot' => null, 'receipt_url' => null,
            ], $caller, $requestId);
        }
        if (! $this->settings->executionEnabled()) {
            throw new ResourceOperationException('proof.execution_disabled', 'Proof execution is switched off: the commit is trusted and the cases are selected, but nothing runs.', 409,
                details: ['commit' => $commit, 'cases' => $selection->programs(), 'uncovered' => array_column($selection->uncovered, 'path')]);
        }
        $slot = $this->slots->claim();
        try {
            $operation = $this->start->execute(OperationKinds::ProofRun, 'node', $swamp->id, $swamp->id, [...$intent,
                'slot' => ['name' => $slot->name, 'checkout_path' => $slot->checkout],
                'ssh_execution' => $this->ssh->forNode(Node::query()->findOrFail($slot->nodeId)),
            ], $caller, $requestId);
        } catch (Throwable $exception) {
            $this->slots->release($slot->name);

            throw $exception;
        }
        // A request that raced an earlier one with the same key got that run back; its slot is the one that counts.
        if (! $operation->wasRecentlyCreated) {
            $this->slots->release($slot->name);
        }

        return $operation;
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

<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\Deployment\AutomaticDeployAlerts;
use App\Domain\Instances\Deployment\AutomaticDeployHistory;
use App\Domain\Instances\Deployment\AutomaticDeployOutcome;
use App\Domain\Instances\Deployment\AutomaticDeployResult;
use App\Domain\Instances\Deployment\AutomaticDeployState;
use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\RemoteBranchHeads;
use App\Models\Instance;
use App\Models\Operation;
use Illuminate\Support\Collection;
use Throwable;

/** Deploys development defaults each minute. Swamp owns production policy. */
final readonly class RunAutomaticDeploysAction
{
    /** The `command` of the tick's Activity entries. */
    public const string Command = AutomaticDeployHistory::Trigger;

    public function __construct(
        private RemoteBranchHeads $heads,
        private StartInstanceDeployAction $deploys,
        private StartInstanceProjectionRepairAction $projections,
        private AutomaticDeployAlerts $alerts,
    ) {}

    /** @return list<AutomaticDeployOutcome> */
    public function execute(): array
    {
        $outcomes = [];

        foreach ($this->developmentDefaults() as $instance) {
            $outcomes[] = $this->tick($instance);
        }

        return $outcomes;
    }

    /** Runs one development default's check and records its result. */
    private function tick(Instance $instance): AutomaticDeployOutcome
    {
        $state = AutomaticDeployState::of($instance);

        try {
            $outcome = $this->development($instance);
        } catch (Throwable $exception) {
            report($exception);
            $outcome = $this->failed($instance, $exception);
        }

        if ($state->result($outcome)) {
            $this->alerts->activity($instance, $outcome);
        }

        $state->save($instance);

        return $outcome;
    }

    private function development(Instance $instance): AutomaticDeployOutcome
    {
        try {
            $head = $this->heads->head($instance->project, (string) $instance->project->default_branch);
        } catch (ResourceOperationException $exception) {
            return $this->failed($instance, $exception);
        }

        if ($instance->development_projection_pending && ! $this->holder($instance) instanceof Operation) {
            try {
                $repair = $this->projections->execute($instance);

                return new AutomaticDeployOutcome($instance->id, AutomaticDeployResult::Busy, $head, $repair->id);
            } catch (RuntimeConvergenceException|ResourceOperationException $exception) {
                return $this->failed($instance, $exception, $head);
            }
        }

        if ($head !== $instance->seed_commit) {
            $history = new AutomaticDeployHistory($instance);

            return in_array($head, $history->blocked(), true)
                ? new AutomaticDeployOutcome($instance->id, AutomaticDeployResult::CommitFailed, $head)
                : $this->deploy($instance, $head, $history);
        }

        return new AutomaticDeployOutcome($instance->id, AutomaticDeployResult::UpToDate, $head);
    }

    /** Starts the operation that deploys exactly `$commit`, unless an operation holds the Instance or the commit waits. */
    private function deploy(Instance $instance, string $commit, AutomaticDeployHistory $history): AutomaticDeployOutcome
    {
        $holder = $this->holder($instance);

        if ($holder instanceof Operation) {
            $own = $holder->kind === OperationKinds::InstanceDeploy
                && $holder->triggered_by === AutomaticDeployHistory::Trigger
                && InstanceDeploymentIntent::commit($holder) === $commit;

            return new AutomaticDeployOutcome($instance->id, $own ? AutomaticDeployResult::Deploying : AutomaticDeployResult::Busy, $commit, $holder->id);
        }

        if ($history->backingOff($commit)) {
            return new AutomaticDeployOutcome($instance->id, AutomaticDeployResult::BackingOff, $commit);
        }

        try {
            $operation = $this->deploys->execute($instance, AutomaticDeployHistory::Trigger, null, $commit);
        } catch (ResourceOperationException $exception) {
            if ($exception->errorCode === 'operation.target_busy') {
                $holderId = $exception->details['operation_id'] ?? null;

                return new AutomaticDeployOutcome($instance->id, AutomaticDeployResult::Busy, $commit, is_int($holderId) ? $holderId : null);
            }

            return $this->failed($instance, $exception, $commit);
        }

        return new AutomaticDeployOutcome($instance->id, AutomaticDeployResult::Started, $commit, $operation->id);
    }

    /** The operation that holds the Instance, if any. */
    private function holder(Instance $instance): ?Operation
    {
        return Operation::query()->where('lock_key', OperationStore::lockKey(OperationKinds::InstanceTarget, $instance->id))->first();
    }

    private function failed(Instance $instance, Throwable $exception, ?string $commit = null): AutomaticDeployOutcome
    {
        $known = $exception instanceof ResourceOperationException || $exception instanceof RuntimeConvergenceException;

        return new AutomaticDeployOutcome(
            $instance->id,
            AutomaticDeployResult::Failed,
            $commit,
            errorCode: $known ? $exception->errorCode : 'deployment.automatic_failed',
            message: $known ? $exception->getMessage() : 'The automatic deployment tick failed for this Instance.',
        );
    }

    /** @return Collection<int, Instance> */
    private function developmentDefaults(): Collection
    {
        return Instance::query()
            ->where('name', 'default')
            ->where('status', InstanceState::Active)
            ->where('source_layout', InstanceSourceLayout::Checkout->value)
            ->whereHas('node.roles', static fn ($query) => $query->where('role', RoleName::AppDev)->where('status', LifecycleStatus::Active))
            ->with(['project', 'node.roles'])
            ->orderBy('id')
            ->get()
            ->filter(static fn (Instance $instance): bool => $instance->placedOnAppDev())
            ->values();
    }
}

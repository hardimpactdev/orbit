<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\Deployment\AutomaticDeployOutcome;
use App\Domain\Instances\Deployment\AutomaticDeployResult;
use App\Domain\Instances\Deployment\InstanceDeployOperation;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\RemoteBranchHeads;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * One tick of automatic deployments, run every minute by `orbit:auto-deploy`
 * ([Automatic deployments](/reference/deployments#automatic-deployments)).
 *
 * For each development default, the Gateway reads the head of the Project's default branch with `git ls-remote`.
 * When the default's seed commit is that head, the tick starts nothing and writes nothing; it only repairs a Route
 * projection that a crash left pending. Otherwise it starts an `instance.deploy` operation pinned to the head, so
 * pushes that arrive meanwhile coalesce into the next tick's head. A commit whose automatic deployment just failed
 * waits {@see self::RetryMinutes} minutes. Another operation on the Instance is a skip, not a failure.
 */
final readonly class RunAutomaticDeploysAction
{
    /** The `triggered_by` of an automatic deployment and its history row. */
    public const string Trigger = 'schedule';

    /** A commit whose automatic deployment failed is tried again after this long. */
    public const int RetryMinutes = 10;

    public function __construct(
        private RemoteBranchHeads $heads,
        private StartInstanceDeployAction $deploys,
        private DeployDefaultInstanceAction $defaults,
    ) {}

    /** @return list<AutomaticDeployOutcome> */
    public function execute(): array
    {
        $outcomes = [];

        foreach ($this->developmentDefaults() as $instance) {
            try {
                $outcomes[] = $this->development($instance);
            } catch (Throwable $exception) {
                report($exception);
                $outcomes[] = $this->failed($instance, $exception);
            }
        }

        return $outcomes;
    }

    private function development(Instance $instance): AutomaticDeployOutcome
    {
        try {
            $head = $this->heads->head($instance->project, (string) $instance->project->default_branch);
        } catch (ResourceOperationException $exception) {
            return $this->failed($instance, $exception);
        }

        if ($head !== $instance->seed_commit) {
            return $this->deploy($instance, $head);
        }

        if ($instance->development_projection_pending && ! $this->holder($instance) instanceof Operation) {
            try {
                $this->defaults->repairProjection($instance);
            } catch (RuntimeConvergenceException|ResourceOperationException $exception) {
                return $this->failed($instance, $exception, $head);
            }
        }

        return new AutomaticDeployOutcome($instance->id, AutomaticDeployResult::UpToDate, $head);
    }

    /** Starts the operation that deploys exactly `$commit`, unless an operation holds the Instance or the commit waits. */
    private function deploy(Instance $instance, string $commit): AutomaticDeployOutcome
    {
        $holder = $this->holder($instance);

        if ($holder instanceof Operation) {
            $own = $holder->kind === OperationKinds::InstanceDeploy
                && $holder->triggered_by === self::Trigger
                && InstanceDeployOperation::commit($holder) === $commit;

            return new AutomaticDeployOutcome($instance->id, $own ? AutomaticDeployResult::Deploying : AutomaticDeployResult::Busy, $commit, $holder->id);
        }

        if ($this->backingOff($instance, $commit)) {
            return new AutomaticDeployOutcome($instance->id, AutomaticDeployResult::BackingOff, $commit);
        }

        try {
            $operation = $this->deploys->execute($instance, self::Trigger, null, $commit);
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
        return Operation::query()->where('lock_key', OperationStore::lockKey('instance', $instance->id))->first();
    }

    /** Whether the commit's last automatic deployment failed less than {@see self::RetryMinutes} minutes ago. */
    private function backingOff(Instance $instance, string $commit): bool
    {
        $last = InstanceDeployment::query()
            ->where('instance_id', $instance->id)
            ->where('triggered_by', self::Trigger)
            ->where('commit', $commit)
            ->latest('id')
            ->first();

        return $last instanceof InstanceDeployment
            && $last->status === 'failed'
            && ($last->finished_at ?? $last->started_at)->gt(Carbon::now()->subMinutes(self::RetryMinutes));
    }

    private function failed(Instance $instance, Throwable $exception, ?string $commit = null): AutomaticDeployOutcome
    {
        return new AutomaticDeployOutcome(
            $instance->id,
            AutomaticDeployResult::Failed,
            $commit,
            errorCode: $exception instanceof ResourceOperationException || $exception instanceof RuntimeConvergenceException ? $exception->errorCode : 'deployment.automatic_failed',
            message: $exception instanceof ResourceOperationException || $exception instanceof RuntimeConvergenceException ? $exception->getMessage() : 'The automatic deployment tick failed for this Instance.',
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

<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GreenCommit;
use App\Domain\GitHub\GreenCommitInspector;
use App\Domain\GitHub\RequiredCheckState;
use App\Domain\Instances\Deployment\AutomaticDeployAlerts;
use App\Domain\Instances\Deployment\AutomaticDeployHistory;
use App\Domain\Instances\Deployment\AutomaticDeployOutcome;
use App\Domain\Instances\Deployment\AutomaticDeployResult;
use App\Domain\Instances\Deployment\AutomaticDeployState;
use App\Domain\Instances\Deployment\InstanceDeployOperation;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStore;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Releases\ReleaseAlertKind;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitCommitSha;
use App\Domain\SourceControl\RemoteBranchHeads;
use App\Infrastructure\GitHub\GitHubTokenCache;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use App\Models\Operation;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * One tick of automatic deployments, run every minute by `orbit:auto-deploy`
 * ([Automatic deployments](/reference/deployments#automatic-deployments)).
 *
 * For each development default, and each production Instance with `auto_deploy`, the Gateway reads the branch head
 * with `git ls-remote`. When the Instance already runs the commit the tick would deploy, the tick starts nothing and
 * writes no history row. Otherwise it starts an `instance.deploy` operation pinned to that commit. Another operation
 * on the Instance is a skip, not a failure.
 *
 * A production Instance deploys only a commit that passed the Project's `merge_check`, found by the
 * {@see GreenCommitInspector}: the newest first-parent commit of the branch that is green, descends from the deployed
 * commit, and is not blocked. The same walk reports the head's own check. The tick reads it again only when the head
 * changed, while the check has not finished, or {@see self::GateFailedRecheckMinutes} minutes after it failed, so a
 * re-run that passes deploys. A manual deployment or a rollback after `auto_deploy` was last set pauses the Instance,
 * and the commit a rollback moved away from stays blocked. {@see AutomaticDeployHistory} decides which failed commits
 * wait for a retry and which are blocked.
 *
 * The tick keeps what it saw in `instances.auto_deploy_state` and writes an Activity entry when a notable result
 * starts. For a production Instance it raises a release alert once for each failed commit, for a pause, and for a
 * commit that waits {@see self::StallAlertMinutes} minutes to deploy.
 */
final readonly class RunAutomaticDeploysAction
{
    /** The `command` of the tick's Activity entries. */
    public const string Command = 'orbit:auto-deploy';

    /** A commit that waits this long to deploy raises the stalled alert once. */
    public const int StallAlertMinutes = 30;

    /** A head whose check failed is read again after this long, in case the check ran again. */
    public const int GateFailedRecheckMinutes = 5;

    public function __construct(
        private RemoteBranchHeads $heads,
        private StartInstanceDeployAction $deploys,
        private DeployDefaultInstanceAction $defaults,
        private GreenCommitInspector $gate,
        private AutomaticDeployAlerts $alerts,
        private ?GitHubTokenCache $tokens = null,
    ) {}

    /** @return list<AutomaticDeployOutcome> */
    public function execute(): array
    {
        $outcomes = [];

        foreach ($this->developmentDefaults() as $instance) {
            $outcomes[] = $this->tick($instance, fn (AutomaticDeployState $state): AutomaticDeployOutcome => $this->development($instance));
        }

        foreach ($this->productionInstances() as $instance) {
            $outcomes[] = $this->tick($instance, fn (AutomaticDeployState $state): AutomaticDeployOutcome => $this->production($instance, $state), alerts: true);
        }

        return $outcomes;
    }

    /**
     * Runs one Instance's check, records its result, and, for a production Instance, watches for a stall.
     *
     * @param  Closure(AutomaticDeployState): AutomaticDeployOutcome  $check
     */
    private function tick(Instance $instance, Closure $check, bool $alerts = false): AutomaticDeployOutcome
    {
        $state = AutomaticDeployState::of($instance);

        try {
            $outcome = $check($state);
        } catch (Throwable $exception) {
            report($exception);
            $outcome = $this->failed($instance, $exception);
        }

        if ($state->result($outcome) && $outcome->result->isNotable()) {
            $this->activity($instance, $outcome);
        }

        if ($alerts) {
            $this->watchStall($instance, $state, $outcome);
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

        if ($head !== $instance->seed_commit) {
            $history = new AutomaticDeployHistory($instance);

            return in_array($head, $history->blocked(production: false), true)
                ? new AutomaticDeployOutcome($instance->id, AutomaticDeployResult::CommitFailed, $head)
                : $this->deploy($instance, $head, $history, production: false);
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

    private function production(Instance $instance, AutomaticDeployState $state): AutomaticDeployOutcome
    {
        $this->alertFailure($instance, $state);
        $history = new AutomaticDeployHistory($instance);
        $pause = $history->pausedBy();

        if ($pause instanceof InstanceDeployment) {
            $rollback = $pause->kind === InstanceDeployment::Rollback;

            if ($state->pauseFor($rollback ? 'rollback' : 'manual_deploy', $pause->id, $pause->commit)) {
                $rolledAway = $rollback ? $history->rolledAwayFrom($pause) : null;
                if ($rolledAway !== null) {
                    $state->block($rolledAway);
                }
                $this->alerts->paused($instance, $pause);
            }
            $state->forgetGate();

            return new AutomaticDeployOutcome(
                $instance->id,
                AutomaticDeployResult::Paused,
                $pause->commit,
                message: ($rollback ? "Rollback {$pause->id}" : "Manual deployment {$pause->id}").' paused automatic deployment. Set auto_deploy again to resume.',
            );
        }

        $state->clearPause();
        $deployed = InstanceDeployment::query()
            ->where('instance_id', $instance->id)
            ->where('status', 'succeeded')
            ->whereNotNull('commit')
            ->latest('id')
            ->value('commit');

        if (! is_string($deployed)) {
            return new AutomaticDeployOutcome($instance->id, AutomaticDeployResult::NotDeployed, message: 'Deploy the Instance by hand once; automatic deployment continues from that commit.');
        }

        $branch = $instance->deployment_branch ?? (string) $instance->branch;

        try {
            $head = $this->heads->head($instance->project, $branch);
        } catch (ResourceOperationException $exception) {
            return $this->failed($instance, $exception);
        }

        $state->head($head);

        if ($head === $deployed) {
            $state->forgetGate();

            return new AutomaticDeployOutcome($instance->id, AutomaticDeployResult::UpToDate, $head);
        }

        $blocked = array_values(array_unique([...$history->blocked(production: true), ...$state->blocked()]));
        $checkName = $instance->project->mergeCheckName();

        if ($checkName === null) {
            return in_array($head, $blocked, true)
                ? new AutomaticDeployOutcome($instance->id, AutomaticDeployResult::CommitFailed, $head)
                : $this->deploy($instance, $head, $history, production: true);
        }

        $settled = $state->settledGate($head, $deployed, $blocked);

        if ($settled instanceof AutomaticDeployResult) {
            return new AutomaticDeployOutcome($instance->id, $settled, $head);
        }

        $repository = GitHubRepository::fromOrigin($instance->project->repository_url);

        if (! $repository instanceof GitHubRepository || $instance->project->source_access !== ProjectSourceAccess::GitHubApp) {
            return new AutomaticDeployOutcome(
                $instance->id,
                AutomaticDeployResult::Failed,
                $head,
                errorCode: 'instance.auto_deploy_gate_unreadable',
                message: 'The Project has a merge_check, but the Gateway GitHub App cannot read its checks.',
            );
        }

        try {
            $inspection = $this->gate->inspect($repository, $branch, $checkName, $deployed, array_values(array_filter($blocked, static fn (string $sha): bool => strlen($sha) === 40)));
        } catch (GitHubApiException|InvalidArgumentException $exception) {
            $this->tokens?->forget($repository);

            return new AutomaticDeployOutcome($instance->id, AutomaticDeployResult::Failed, $head, errorCode: 'instance.auto_deploy_gate_unavailable', message: $exception->getMessage());
        }

        if ($inspection->green instanceof GreenCommit) {
            return $this->deploy($instance, $inspection->green->sha, $history, production: true);
        }

        // The walk read the head that `ls-remote` saw; a head that moved in between is read again on the next tick.
        if ($inspection->head !== $head) {
            return new AutomaticDeployOutcome($instance->id, AutomaticDeployResult::GatePending, $head);
        }

        $result = match ($inspection->headState) {
            RequiredCheckState::Failed => AutomaticDeployResult::GateFailed,
            RequiredCheckState::Passed, null => AutomaticDeployResult::NoGreenCommit,
            default => AutomaticDeployResult::GatePending,
        };

        if ($result === AutomaticDeployResult::NoGreenCommit) {
            $state->settleGate($head, $deployed, $blocked, $result);
        } elseif ($result === AutomaticDeployResult::GateFailed) {
            $state->settleGate($head, $deployed, $blocked, $result, self::GateFailedRecheckMinutes);
        }

        return new AutomaticDeployOutcome($instance->id, $result, $head);
    }

    /** Starts the operation that deploys exactly `$commit`, unless an operation holds the Instance or the commit waits. */
    private function deploy(Instance $instance, string $commit, AutomaticDeployHistory $history, bool $production): AutomaticDeployOutcome
    {
        $holder = $this->holder($instance);

        if ($holder instanceof Operation) {
            $own = $holder->kind === OperationKinds::InstanceDeploy
                && $holder->triggered_by === AutomaticDeployHistory::Trigger
                && InstanceDeployOperation::commit($holder) === $commit;

            return new AutomaticDeployOutcome($instance->id, $own ? AutomaticDeployResult::Deploying : AutomaticDeployResult::Busy, $commit, $holder->id);
        }

        if ($history->backingOff($commit, $production)) {
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

    /** Raises the failed alert once for each commit whose automatic deployment failed since `auto_deploy` was set. */
    private function alertFailure(Instance $instance, AutomaticDeployState $state): void
    {
        $last = InstanceDeployment::query()
            ->where('instance_id', $instance->id)
            ->where('kind', 'deploy')
            ->where('triggered_by', AutomaticDeployHistory::Trigger)
            ->where('status', '!=', 'running')
            ->where(static fn ($query) => $query->whereNull('error_code')->orWhere('error_code', '!=', AutomaticDeployHistory::Paused))
            ->where('id', '>', $instance->auto_deploy_resumed_after)
            ->latest('id')
            ->first();

        if ($last instanceof InstanceDeployment && $last->status === 'failed' && is_string($last->commit)
            && $state->alertOnce(ReleaseAlertKind::InstanceDeployFailed->value, $last->commit)) {
            $this->alerts->failed($instance, $last);
        }
    }

    /**
     * A commit the tick would deploy, or a head whose check has not finished, that has waited
     * {@see self::StallAlertMinutes} minutes raises the stalled alert once. Any other result ends the stall.
     */
    private function watchStall(Instance $instance, AutomaticDeployState $state, AutomaticDeployOutcome $outcome): void
    {
        $sha = match ($outcome->result) {
            AutomaticDeployResult::Started, AutomaticDeployResult::Deploying, AutomaticDeployResult::Busy, AutomaticDeployResult::BackingOff,
            AutomaticDeployResult::GatePending, AutomaticDeployResult::Failed => $outcome->commit,
            default => null,
        };

        if ($sha === null || ! GitCommitSha::isValid($sha)) {
            $state->clearStall();

            return;
        }

        if ($state->stalled($sha, self::StallAlertMinutes)) {
            $this->alerts->stalled($instance, $sha, $state->stall()['since'] ?? '', $outcome);
        }
    }

    /** The operation that holds the Instance, if any. */
    private function holder(Instance $instance): ?Operation
    {
        return Operation::query()->where('lock_key', OperationStore::lockKey('instance', $instance->id))->first();
    }

    private function activity(Instance $instance, AutomaticDeployOutcome $outcome): void
    {
        try {
            Activity::query()->create([
                'log_name' => 'deployments',
                'description' => 'auto_deploy.'.$outcome->result->value,
                'subject_type' => Instance::MorphAlias,
                'subject_id' => $instance->id,
                'properties' => [
                    'instance_id' => $instance->id,
                    'commit' => $outcome->commit,
                    'operation_id' => $outcome->operationId,
                    'error_code' => $outcome->errorCode,
                    'message' => $outcome->message,
                ],
                'request_id' => (string) Str::uuid(),
                'command' => self::Command,
                'status' => $outcome->result->isError() ? 'failed' : 'completed',
                'error_code' => $outcome->errorCode,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
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

    /** @return Collection<int, Instance> */
    private function productionInstances(): Collection
    {
        return Instance::query()
            ->where('auto_deploy', true)
            ->where('status', InstanceState::Active)
            ->whereHas('node.roles', static fn ($query) => $query->where('role', RoleName::AppProd)->where('status', LifecycleStatus::Active))
            ->with(['project', 'node.roles'])
            ->orderBy('id')
            ->get()
            ->filter(static fn (Instance $instance): bool => $instance->placedOnAppProd())
            ->values();
    }
}

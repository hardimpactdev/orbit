<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\GitHub\GitHubRepository;
use App\Domain\Releases\ReleaseAlert;
use App\Domain\Releases\ReleaseAlertKind;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Domain\Releases\ReleaseAlertSubject;
use App\Domain\Releases\ReleaseIncident;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Raises the release alerts of automatic production deployments: an Activity entry, a filed problem, and the signed
 * webhook ([Release alerts](/reference/gateway-recovery#release-alerts)). The target is `instance:{id}`. The tick
 * calls each once per commit, outside a transaction. A Project outside GitHub has no alert repository, so its alert is
 * skipped; the history row and the tick's Activity entry still record what happened.
 */
final readonly class AutomaticDeployAlerts
{
    public function __construct(private ReleaseAlertNotifier $notifier) {}

    /** @return array<string, mixed> */
    public function activity(Instance $instance, AutomaticDeployOutcome $outcome, ?string $requestId = null): array
    {
        if (! $outcome->result->isNotable()) {
            return ['outcome' => 'skipped', 'reason' => 'not_notable'];
        }

        try {
            $activity = Activity::query()->create([
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
                'request_id' => $requestId ?? (string) Str::uuid(),
                'command' => AutomaticDeployHistory::Trigger,
                'status' => $outcome->result->isError() ? 'failed' : 'completed',
                'error_code' => $outcome->errorCode,
            ]);

            return ['activity_id' => $activity->id];
        } catch (Throwable $exception) {
            report($exception);

            return ['outcome' => 'failed', 'reason' => 'activity_unavailable'];
        }
    }

    /** @return array<string, mixed> */
    public function failed(Instance $instance, InstanceDeployment $deployment): array
    {
        return $this->raise(ReleaseAlertKind::InstanceDeployFailed, $instance, (string) $deployment->commit, $deployment, sprintf(
            'The automatic deployment %d of Instance %d failed at %s: %s. %s',
            $deployment->id,
            $instance->id,
            $deployment->failed_step ?? 'unknown',
            $deployment->error_code ?? 'unknown',
            AutomaticDeployFailure::isTransient($deployment)
                ? sprintf('The commit is tried again after a backoff, %d times at most, until a new commit or a manual deployment.', AutomaticDeployHistory::MaxAttempts)
                : 'The commit does not deploy automatically again until a new commit or a manual deployment.',
        ));
    }

    /** @return array<string, mixed> */
    public function stalled(Instance $instance, string $sha, string $since, AutomaticDeployOutcome $outcome): array
    {
        return $this->raise(ReleaseAlertKind::InstanceDeployStalled, $instance, $sha, null, sprintf(
            'Commit %s of Instance %d has waited to deploy since %s. The last tick: %s%s.',
            substr($sha, 0, 12),
            $instance->id,
            $since,
            $outcome->result->value,
            $outcome->errorCode !== null ? " {$outcome->errorCode}" : '',
        ));
    }

    /**
     * The alert of a manual deployment or rollback that paused automatic deployment. A pause without a commit, such as
     * a manual deployment that failed before it fetched one, has no alert subject; its Activity entry records it.
     *
     * @return array<string, mixed>
     */
    public function paused(Instance $instance, InstanceDeployment $pause): array
    {
        if (! is_string($pause->commit)) {
            return ['kind' => ReleaseAlertKind::InstanceDeployPaused->value, 'outcome' => 'skipped', 'reason' => 'commit_missing'];
        }

        return $this->raise(ReleaseAlertKind::InstanceDeployPaused, $instance, $pause->commit, $pause, sprintf(
            '%s %d of Instance %d paused automatic deployment. Run `orbit instance:update %d --auto-deploy=true` to resume.',
            $pause->kind === InstanceDeployment::Rollback ? 'Rollback' : 'Manual deployment',
            $pause->id,
            $instance->id,
            $instance->id,
        ));
    }

    /** @return array<string, mixed> */
    private function raise(ReleaseAlertKind $kind, Instance $instance, string $sha, ?InstanceDeployment $deployment, string $summary): array
    {
        $repository = GitHubRepository::fromOrigin($instance->project->repository_url);

        if (! $repository instanceof GitHubRepository) {
            return ['kind' => $kind->value, 'outcome' => 'skipped', 'reason' => 'repository_unsupported'];
        }

        try {
            $alert = new ReleaseAlert(
                $kind,
                new ReleaseAlertSubject('instance:'.$instance->id, $repository->owner.'/'.$repository->name, $sha, $deployment instanceof InstanceDeployment ? 'deployment-'.$deployment->id : null),
                $summary,
                new ReleaseIncident('instance:'.$instance->id, $deployment instanceof InstanceDeployment ? (string) $deployment->id : 'pending:'.$sha, $deployment->error_code ?? $kind->value),
            );

            return ['kind' => $kind->value, ...$this->notifier->alert($alert)->toArray()];
        } catch (InvalidArgumentException $exception) {
            report($exception);

            return ['kind' => $kind->value, 'outcome' => 'skipped', 'reason' => 'subject_invalid'];
        }
    }
}

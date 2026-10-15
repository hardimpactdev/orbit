<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\GitHub\GitHubRepository;
use App\Domain\Releases\ReleaseAlert;
use App\Domain\Releases\ReleaseAlertKind;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Domain\Releases\ReleaseAlertSubject;
use App\Models\Instance;
use App\Models\InstanceDeployment;
use InvalidArgumentException;

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
    public function failed(Instance $instance, InstanceDeployment $deployment): array
    {
        return $this->raise(ReleaseAlertKind::InstanceDeployFailed, $instance, (string) $deployment->commit, $deployment, sprintf(
            'The automatic deployment %d of Instance %d failed at %s: %s. The commit does not deploy automatically again until a new commit or a manual deployment%s.',
            $deployment->id,
            $instance->id,
            $deployment->failed_step ?? 'unknown',
            $deployment->error_code ?? 'unknown',
            AutomaticDeployFailure::isTransient($deployment) ? ', except for a retry after 10 minutes' : '',
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

    /** @return array<string, mixed> */
    public function paused(Instance $instance, InstanceDeployment $rollback): array
    {
        return $this->raise(ReleaseAlertKind::InstanceDeployPaused, $instance, (string) $rollback->commit, $rollback, sprintf(
            'Rollback %d of Instance %d paused automatic deployment. Run `orbit instance:update %d --auto-deploy=true` to resume.',
            $rollback->id,
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
            );

            return ['kind' => $kind->value, ...$this->notifier->alert($alert)->toArray()];
        } catch (InvalidArgumentException $exception) {
            report($exception);

            return ['kind' => $kind->value, 'outcome' => 'skipped', 'reason' => 'subject_invalid'];
        }
    }
}

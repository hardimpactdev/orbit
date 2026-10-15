<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

/** What one automatic deployment tick did for one Instance ([Automatic deployments](/reference/deployments#automatic-deployments)). */
enum AutomaticDeployResult: string
{
    /** The deployed commit is the one the tick would deploy. Nothing started and nothing was written. */
    case UpToDate = 'up_to_date';

    /** The tick started an `instance.deploy` operation pinned to the commit. */
    case Started = 'started';

    /** The tick's own operation for this commit has not ended yet. */
    case Deploying = 'deploying';

    /** Another operation holds the Instance. The next tick looks again. */
    case Busy = 'busy';

    /** The commit's last automatic deployment failed for a transient cause less than ten minutes ago. */
    case BackingOff = 'backing_off';

    /** The tick could not read the branch head or the gate, or start the deployment. */
    case Failed = 'failed';

    /** A rollback after automatic deployment was last set pauses it until `auto_deploy` is set again. */
    case Paused = 'paused';

    /** The Instance has no succeeded deployment yet. Its first deployment is manual. */
    case NotDeployed = 'not_deployed';

    /** The merge check of the branch head has not finished, and no older commit is green. */
    case GatePending = 'gate_pending';

    /** The merge check of the branch head failed, and no older commit is green. */
    case GateFailed = 'gate_failed';

    /** The branch head passed its merge check, but no commit may deploy: it failed a deployment, or it does not descend from the deployed commit. */
    case NoGreenCommit = 'no_green_commit';

    /** The commit's automatic deployment failed. A new commit or a manual deployment clears it. */
    case CommitFailed = 'commit_failed';

    /** Whether the tick should report this result as an error. */
    public function isError(): bool
    {
        return $this === self::Failed;
    }

    /** Whether a change to this result is worth an Activity entry: a start, a skip that matters, or a failure. */
    public function isNotable(): bool
    {
        return ! in_array($this, [self::UpToDate, self::Deploying, self::GatePending], true);
    }
}

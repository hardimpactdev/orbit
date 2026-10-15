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

    /** The tick could not read the branch head, or start the deployment. */
    case Failed = 'failed';

    /** Whether the tick should report this result as an error. */
    public function isError(): bool
    {
        return $this === self::Failed;
    }
}

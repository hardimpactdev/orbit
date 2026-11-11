<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Models\InstanceDeployment;

/**
 * Sorts a failed automatic deployment of a production Instance. A failure before any of the Project's own steps ran
 * is transient: the commit is tried again after the backoff. Any other failure, and one whose cause lasts, keeps the
 * commit from deploying automatically until a new commit or a manual deployment.
 */
final class AutomaticDeployFailure
{
    /** Boundaries where only Orbit's own work ran. */
    private const array TransientBoundaries = ['operation', 'preparation', 'environment'];

    /** Error codes at those boundaries whose cause a retry does not change. */
    private const array LastingCodes = [
        'deployment.cancelled',
        'deployment.commit_not_on_branch',
        'deployment.repository_mismatch',
        'deployment.repository_rebind_required',
        'deployment_config.unavailable',
    ];

    public static function isTransient(InstanceDeployment $deployment): bool
    {
        return in_array($deployment->failed_step, self::TransientBoundaries, true)
            && ! in_array($deployment->error_code, self::LastingCodes, true);
    }
}

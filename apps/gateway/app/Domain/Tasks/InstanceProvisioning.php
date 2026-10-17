<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;

/**
 * Assigns the one Instance that a task group shares.
 *
 * A no-op implementation returns null.
 * TaskWorkspaceProvisioner creates one fresh instance per group, honors
 * visitable, places it on an app-dev Node that supports the selected agent driver, and reuses that
 * instance for every subtask. It returns the instance the group holds, and resumes the unattached
 * instance the group owns on its own Node.
 *
 * A typed failure carries the cause; a null result means no cause is available.
 * A wait throws TaskCapacityException instead, and a failed task VM throws TaskVmFailedException.
 */
interface InstanceProvisioning
{
    /** @throws TaskCapacityException when every Node that could host the group is at the task ceiling. */
    public function provision(InstanceProvisionIntent $intent): Instance|InstanceProvisionFailure|null;
}

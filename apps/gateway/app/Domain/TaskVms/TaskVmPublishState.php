<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

/** Where the push of a task VM's approved commit is. A VM that has asked for no push has no state. */
enum TaskVmPublishState: string
{
    /** The publish job waits or runs. */
    case Queued = 'queued';

    /** GitHub has the commit at the branch. */
    case Pushed = 'pushed';

    /** The job failed. `publish_error` holds the reason, which the next push reports once. */
    case Failed = 'failed';
}

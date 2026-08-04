<?php

declare(strict_types=1);

namespace App\Enums;

enum ProcessEventType: string
{
    /** Command accepted; runtime start not finished. */
    case Starting = 'starting';

    /** Runtime reported a successful start. */
    case Started = 'started';

    /** Command accepted; runtime stop not finished. */
    case Stopping = 'stopping';

    /** Runtime reported a successful stop. */
    case Stopped = 'stopped';

    /** Command accepted; runtime restart not finished. */
    case Restarting = 'restarting';

    /** Runtime hook reported an unexpected exit. */
    case Crashed = 'crashed';

    /**
     * Lifecycle action failed before a truthful running/stopped/crashed terminal.
     * Maps to process status `unknown` (not fabricated crashed/running/stopped).
     */
    case Failed = 'failed';
}

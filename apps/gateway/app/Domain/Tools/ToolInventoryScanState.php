<?php

declare(strict_types=1);

namespace App\Domain\Tools;

/**
 * The scan states from the tool inventory contract.
 * Packages are an inventory only when the state is complete.
 */
enum ToolInventoryScanState: string
{
    case Complete = 'complete';
    case Unsupported = 'unsupported';
    /** The Node agent has not reported this manager: the agent is new or silent, or the manager isn't installed. */
    case NotObserved = 'not_observed';
}

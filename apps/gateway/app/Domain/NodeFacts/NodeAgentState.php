<?php

declare(strict_types=1);

namespace App\Domain\NodeFacts;

enum NodeAgentState: string
{
    /** An agent event arrived in the last 15 seconds. */
    case Online = 'online';

    /** The agent was seen, but not in the last 15 seconds; `since` names its last event. */
    case Offline = 'offline';

    /** The Gateway never received an event from this Node's agent. */
    case NotSeen = 'not_seen';

    /** The agent view subscriber is down, so the Gateway cannot tell. */
    case Unknown = 'unknown';
}

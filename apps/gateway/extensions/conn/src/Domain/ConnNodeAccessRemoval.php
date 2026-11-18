<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Nodes\NodeAccessRemovalHook;
use App\Models\Node;

/**
 * A removed grant replaces the consumer's profile event key, whatever access remains ([Profile events](/reference/conn#profile-events)).
 * It also withdraws the consumer's held sessions on the servers it no longer reaches and admits their revoke
 * ([Held sessions](/reference/conn#held-sessions)). A revoke the server cannot take now waits for the hourly sweep.
 */
final readonly class ConnNodeAccessRemoval implements NodeAccessRemovalHook
{
    public function __construct(private ConnHeldSessions $held, private ConnProfileEvents $events) {}

    public function removed(Node $consumer, Node $serving): void
    {
        $this->events->replaceForNode($consumer->id);
        $this->held->withdrawUnreachable($consumer);
    }
}

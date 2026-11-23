<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Nodes\NodeRemovalHook;
use App\Models\Node;
use Orbit\Extensions\Conn\Domain\Push\ConnPushDevices;

/**
 * A deleted Node's held sessions must not outlive it: they are withdrawn, and the revokes they owe pass to the Gateway Node, which
 * made them ([Held sessions](/reference/conn#held-sessions)). Every Node with an edge to it gets a new profile event key before the
 * cascade deletes those edges ([Profile events](/reference/conn#profile-events)), and its registered push devices are removed.
 */
final readonly class ConnNodeRemoval implements NodeRemovalHook
{
    public function __construct(
        private ConnHeldSessions $held,
        private ConnProfileEvents $events,
        private ConnPushDevices $pushDevices,
    ) {}

    public function removing(Node $node): void
    {
        $this->events->replaceForConsumersOf($node);
        $this->held->handOver($node);
        $this->pushDevices->removeForNode($node->id);
    }
}

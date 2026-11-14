<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Nodes\NodeRemovalHook;
use App\Models\Node;

/**
 * A deleted Node's held sessions must not outlive it: they are withdrawn, and the revokes they owe pass to the Gateway Node, which
 * made them ([Held sessions](/reference/conn#held-sessions)).
 */
final readonly class ConnNodeRemoval implements NodeRemovalHook
{
    public function __construct(private ConnHeldSessions $held) {}

    public function removing(Node $node): void
    {
        $this->held->handOver($node);
    }
}

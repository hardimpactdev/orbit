<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Nodes\NodeAccessRemovalHook;
use App\Models\Node;
use Illuminate\Support\Facades\DB;

/**
 * A removed grant replaces the consumer's profile event key, whatever access remains ([Profile events](/reference/conn#profile-events)).
 * It also withdraws the consumer's held sessions on the servers it no longer reaches and admits their revoke
 * ([Held sessions](/reference/conn#held-sessions)) after the outermost commit. A revoke the server cannot take now waits for the hourly sweep.
 */
final readonly class ConnNodeAccessRemoval implements NodeAccessRemovalHook
{
    public function __construct(private ConnHeldSessions $held, private ConnProfileEvents $events) {}

    public function removed(Node $consumer, Node $serving): void
    {
        $this->events->replaceForNode($consumer->id);
        // The withdrawal runs after the outermost commit, in its own transaction, apart from managed revocation
        // ([Revision order](/reference/conn#revision-order)).
        DB::afterCommit(fn () => $this->held->withdrawUnreachable($consumer));
    }
}

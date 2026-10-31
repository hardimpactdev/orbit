<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Infrastructure\Nodes\NodeWorkerRollout;
use App\Models\Node;

/** The approved light Nodes keep their exact logical identity through lane21's SSH proxy. */
final readonly class NodeRolePlacement
{
    private const array ProxyNodes = [1, 18];

    public function allows(Node $node): bool
    {
        return app(NodeWorkerRollout::class)->allows($node) ||
            (in_array($node->id, self::ProxyNodes, true) && app(ManagedNodeEligibility::class)->allows($node)
                && in_array($node->architecture, ['x86_64', 'amd64'], true));
    }

    /** @return list<int> */
    public function proxyNodeIds(): array
    {
        $ids = [];
        foreach (Node::query()->whereIn('id', self::ProxyNodes)->get() as $node) {
            if ($this->allows($node)) {
                $ids[] = $node->id;
            }
        }

        return $ids;
    }
}

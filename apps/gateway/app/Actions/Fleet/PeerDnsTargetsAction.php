<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Node;

final readonly class PeerDnsTargetsAction
{
    public function __construct(private PeerDnsPolicy $policy) {}

    /** @return list<int> */
    public function execute(): array
    {
        $this->policy->domains();

        $targets = Node::query()->with('roles')->orderBy('id')->get()
            ->filter(fn (Node $node): bool => $this->policy->manages($node))
            ->map(static fn (Node $node): int => $node->id)->all();

        return array_values($targets);
    }
}

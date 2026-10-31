<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Node;

final readonly class PeerDnsTargetsAction
{
    public function __construct(private PeerDnsPolicy $policy) {}

    /** @return list<array{node_id:int, configuration_digest:string}> */
    public function execute(): array
    {
        $domains = $this->policy->domains();

        $targets = Node::query()->with('roles')->orderBy('id')->get()
            ->filter(fn (Node $node): bool => $this->policy->manages($node))
            ->map(fn (Node $node): array => [
                'node_id' => $node->id,
                'configuration_digest' => hash('sha256', json_encode([
                    $this->policy->server($node), $node->wireguard_public_key, $domains,
                ], JSON_THROW_ON_ERROR)),
            ])->all();

        return array_values($targets);
    }
}

<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Firewall;

final readonly class FirewallRulesResponse
{
    /** @param list<FirewallNodeRulesResponse> $nodes */
    public function __construct(
        public array $nodes,
        public string $requestId,
    ) {}

    /** @return array{nodes: list<array<string, mixed>>, request_id: string} */
    public function toArray(): array
    {
        return [
            'nodes' => array_map(static fn (FirewallNodeRulesResponse $node): array => $node->toArray(), $this->nodes),
            'request_id' => $this->requestId,
        ];
    }
}

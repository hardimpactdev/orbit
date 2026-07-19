<?php

declare(strict_types=1);

namespace App\Services\Dns;

use App\Enums\Nodes\NodeRoleName;
use App\Models\Node;
use Illuminate\Support\Collection;
use Illuminate\Support\Enumerable;
use Orbit\Core\Nodes\NodeTld;

final readonly class NodeDnsmasqRecordsBuilder
{
    /**
     * @param  Enumerable<int, Node>|iterable<int, Node>  $nodes
     */
    public function build(iterable $nodes): string
    {
        /** @var Collection<int, Node> $resolvable */
        $resolvable = Collection::make($nodes)
            ->filter($this->isResolvable(...))
            ->sortBy(static fn (Node $node): string => $node->tld)
            ->values();

        return implode("\n", [
            '# orbit-managed=node-dns-records',
            ...$resolvable->flatMap($this->directivesFor(...))->all(),
            '',
        ]);
    }

    public function buildGatewayState(): string
    {
        return $this->build(Node::query()->with('roleAssignments')->get());
    }

    /** @return list<string> */
    public function directivesFor(Node $node): array
    {
        if (! $this->isResolvable($node)) {
            return [];
        }

        $tld = $node->tld;
        $address = $node->wireguard_address;
        $directives = ["address=/orbit.{$tld}/{$address}"];

        if (! $this->hasWildcardDnsRole($node)) {
            return $directives;
        }

        return [
            ...$directives,
            "address=/{$tld}/{$address}",
            "local=/{$tld}/",
        ];
    }

    private function isResolvable(Node $node): bool
    {
        return ! in_array(
            false,
            [
                $node->isActive(),
                NodeTld::isValid($node->tld),
                filled($node->wireguard_address),
            ],
            strict: true,
        );
    }

    private function hasWildcardDnsRole(Node $node): bool
    {
        return array_any(
            [NodeRoleName::AppDevelopment, NodeRoleName::Agent],
            static fn (NodeRoleName $role): bool => $node->hasActiveRole($role->value),
        );
    }
}

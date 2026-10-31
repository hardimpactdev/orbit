<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Models\Node;
use Closure;
use Illuminate\Container\Attributes\Scoped;

/** Request-scoped prospective data. Stored Route ownership and actual Node identity stay authoritative. */
#[Scoped]
final class RouteRenderContext
{
    private ?NativeRouteNodeRender $candidate = null;

    /** The owning parent authenticates and selects its immutable admitted candidate before calling.
     * @template T
     *
     * @param  Closure():T  $render
     * @return T
     */
    public function render(NativeRouteNodeRender $candidate, Closure $render): mixed
    {
        Node::query()->findOrFail($candidate->nodeId);
        $previous = $this->candidate;
        $this->candidate = $candidate;
        try {
            return $render();
        } finally {
            $this->candidate = $previous;
        }
    }

    public function nodeId(): ?int
    {
        return $this->candidate?->nodeId;
    }

    public function wireguardIp(Node $node): ?string
    {
        return $this->candidate?->nodeId === $node->id ? $this->candidate->wireguardIp : $node->wireguard_ip;
    }

    public function tld(Node $node): ?string
    {
        return $this->candidate?->nodeId === $node->id ? $this->candidate->tld : $node->tld;
    }
}

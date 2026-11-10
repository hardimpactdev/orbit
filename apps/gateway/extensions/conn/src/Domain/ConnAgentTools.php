<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Fleet\AgentToolsContribution;
use App\Models\Node;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

/**
 * Names the hosts of the daily agent tool update (ADR 0255): each Node that runs a registered T3 server on its own host, at the
 * Node's WireGuard address. The T3 server of a container Instance is left out, as is one that another Node registered: a
 * container runs the shared agent folder, not the host's CLIs.
 */
final readonly class ConnAgentTools implements AgentToolsContribution
{
    public function __construct(private ExtensionStore $extensions) {}

    public function hosts(): array
    {
        if (! $this->extensions->enabled('conn')) {
            return [];
        }
        $nodes = [];
        foreach (ConnEnvironment::query()->whereNull('instance_id')->whereNotNull('node_id')->with('node')->orderBy('node_id')->get() as $environment) {
            $node = $environment->node;
            if ($node instanceof Node && is_string($node->wireguard_ip) && parse_url($environment->url, PHP_URL_HOST) === $node->wireguard_ip) {
                $nodes[$node->id] = $node;
            }
        }

        return array_values($nodes);
    }

    public function router(): ?array
    {
        return null;
    }
}

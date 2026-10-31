<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Models\Node;

/** Fresh public whole-Node artifacts read while the caller holds its native publication lock. */
final readonly class NativeRoutePublicationInput
{
    /** @return array<string,mixed> */
    public function forNode(Node $node): array
    {
        return ['caddy_publication' => CaddyPublicationPlan::forNode($node),
            'php_publication' => ['node_id' => $node->id, 'agent_sha256' => app(AgentDelivery::class)->pin($node),
                ...app(SharedPhpPoolInput::class)->forNode($node)],
            'private_dns_publication' => PrivateDnsPublicationNodes::query()->whereKey($node->id)->exists() ? $this->dns($node) : null];
    }

    /** Node/role removal intent is stored before this render; Route associations stay attached.
     * @param  array<int,array<string,mixed>>  $nodeOverrides
     * @param  array<int,array<string,mixed>>  $clusterOverrides
     * @return array<string,mixed>
     */
    public function dns(Node $node, ?Node $pendingNode = null, array $nodeOverrides = [], array $clusterOverrides = []): array
    {
        $renderer = app(DevelopmentDnsConfigRenderer::class);
        $records = $renderer->render($pendingNode, $nodeOverrides, $clusterOverrides);
        $catalog = json_encode(['requesters' => $renderer->registeredRequesters(),
            ...$renderer->catalog($pendingNode, $nodeOverrides, $clusterOverrides)->toPublished()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";

        return ['node_id' => $node->id, 'records' => $records, 'catalog' => $catalog, 'digest' => hash('sha256', $catalog)];
    }
}

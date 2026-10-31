<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Clusters\ClusterState;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Models\Node;
use Illuminate\Support\Collection;

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

    /** Admission-only snapshot reused by Route and composing parent workflows.
     * @param  Collection<int,DevelopmentSite>  $sites
     * @return array<string,mixed>
     */
    public function snapshot(Node $node, Collection $sites): array
    {
        return app(RoutePublicationSites::class)->render($sites, function () use ($node, $sites): array {
            $caddy = app(NodeCaddyfileRenderer::class)->render($node);
            if (! $caddy->buildable()) {
                throw new ResourceOperationException('caddy.render_failed', 'The admitted Route publication cannot be rendered.', 409);
            }
            $scopes = $sites->where('nodeId', $node->id)->reject(static fn (DevelopmentSite $site): bool => $site->publicListener)
                ->map(static fn (DevelopmentSite $site): string => $site->certificateScope ?? $site->scope)->unique()->sort()->values()->all();

            return ['caddy' => [...CaddyPublicationPlan::input($caddy, $node->id), 'blocks' => $caddy->blocks],
                'php' => app(SharedPhpPoolInput::class)->forNode($node),
                'dns' => PrivateDnsPublicationNodes::query()->whereKey($node->id)->exists() ? $this->dns($node) : null,
                'certificate_scopes' => $scopes];
        });
    }

    /** Node/role removal intent is stored before this render; Route associations stay attached.
     * @param  array<int,array{cluster_id?:?int,lan_ip?:?string,status?:LifecycleStatus,wireguard_ip?:?string,wireguard_public_key?:?string}>  $nodeOverrides
     * @param  array<int,array{state?:ClusterState,tld?:?string,router_node_id?:?int}>  $clusterOverrides
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

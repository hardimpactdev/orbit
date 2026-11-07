<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\PrivateDnsPublication;
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
            'private_dns_publication' => PrivateDnsPublicationNodes::query()->whereKey($node->id)->exists() ? $this->dns() : null];
    }

    /** Admission-only snapshot reused by Route and composing parent workflows.
     * @param  Collection<int,DevelopmentSite>  $sites
     * @param  bool  $complete  The sites are the whole fleet that a Route change leaves behind, so private DNS follows them.
     * @return array<string,mixed>
     */
    public function snapshot(Node $node, Collection $sites, bool $complete = false): array
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
                'dns' => PrivateDnsPublicationNodes::query()->whereKey($node->id)->exists() ? $this->dns() : null,
                'certificate_scopes' => $scopes];
        }, $complete);
    }

    /**
     * The complete private DNS, rendered from stored rows with its revision. A change stores its rows before it renders.
     *
     * @return array<string,mixed>
     */
    public function dns(): array
    {
        return app(PrivateDnsPublication::class)->render();
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build\Sources;

use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteRenderContext;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Caddy\Build\CaddyListenerRule;
use App\Infrastructure\Caddy\Build\CaddySite;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Caddy\Build\CaddySiteRoles;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Infrastructure\Shared\StoredValue;
use App\Infrastructure\WebSocket\WebSocketCaddySiteRenderer;
use App\Infrastructure\WebSocket\WebSocketFootprint;
use App\Models\Node;
use App\Models\NodeRole;

final readonly class WebSocketCaddySiteSource implements NodeCaddySiteSource
{
    public const string CertificateSlug = 'websocket';

    public function __construct(
        private WebSocketCaddySiteRenderer $renderer = new WebSocketCaddySiteRenderer,
        private int $port = 0,
        private CaddySiteCertificates $certificates = new CaddySiteCertificates,
    ) {}

    public function order(): int
    {
        return 50;
    }

    public function sites(Node $node): array
    {
        // During a move the role row already names the target, but the source keeps its site, and its
        // record, until the move withdraws it there after the target serves and DNS has moved.
        if (
            ! $this->certificates->published($node->id, WebSocketCaddySiteSource::CertificateSlug)
            || CaddySiteRoles::serving(RoleName::WebSocket) === []
        ) {
            return [];
        }

        $gateway = NodeRole::query()->where('role', RoleName::Gateway->value)->where('status', LifecycleStatus::Active->value)->with('node')->first();

        $upstream = $this->port > 0 ? $this->port : StoredValue::integer(config('orbit.websocket.port'));
        $gatewayAddress = $gateway === null ? null : app(RouteRenderContext::class)->wireguardIp($gateway->node);

        // The shared 443 listener for the Gateway, task VMs and browsers, and a listener of its own on the Instance port: Caddy routes
        // the shared one by Host whatever the SNI, so an Instance container's exact path admits only the second.
        return array_map(fn (int $listen): CaddySite => new CaddySite(
            source: 'websocket',
            name: WebSocketFootprint::Hostname.($listen === 443 ? '' : ':'.$listen),
            listener: CaddyListenerRule::Shared,
            hosts: [WebSocketFootprint::Hostname],
            port: $listen,
            body: $this->renderer->render($upstream, $gatewayAddress, $listen),
            bindPlaceholder: WebSocketFootprint::CaddyBindPlaceholder,
        ), [443, WebSocketFootprint::InstancePort]);
    }
}

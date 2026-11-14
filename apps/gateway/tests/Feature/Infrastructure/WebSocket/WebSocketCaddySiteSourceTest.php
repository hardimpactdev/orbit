<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Caddy\Build\CaddyListenerRule;
use App\Infrastructure\Caddy\Build\CaddySite;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Infrastructure\Caddy\Build\Sources\WebSocketCaddySiteSource;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

function websocketSiteNode(): Node
{
    $gateway = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.2', 'public_ssh_host' => '10.44.0.2', 'user' => 'orbit']));
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'services', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '10.44.0.3', 'user' => 'orbit']));
    $node->roles()->create(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Active]);
    app(CaddySiteCertificates::class)->record($node->id, WebSocketCaddySiteSource::CertificateSlug);

    return $node->refresh();
}

it('serves reverb.orbit on the shared 443 listener as before, and alone on a listener of its own at the Instance port', function (): void {
    $node = websocketSiteNode();
    // Another private site on the same Node, as analytics.orbit is on the services Node.
    $other = new class implements NodeCaddySiteSource
    {
        public function order(): int
        {
            return 60;
        }

        public function sites(Node $node): array
        {
            return [new CaddySite(source: 'analytics', name: 'analytics.orbit',
                listener: CaddyListenerRule::Shared, hosts: ['analytics.orbit'], port: 443,
                body: "analytics.orbit {\n    bind __BIND__\n    respond \"analytics\"\n}\n", bindPlaceholder: '__BIND__')];
        }
    };

    $caddyfile = (new NodeCaddyfileRenderer([new WebSocketCaddySiteSource(port: 9001), $other]))->render($node);
    $blocks = collect($caddyfile->blocks)->keyBy('name');

    expect($caddyfile->problems)->toBe([])
        // 443 is unchanged for the Gateway, task VMs and browsers: the same site address, bind, upstream and Gateway-only control path.
        ->and($blocks['reverb.orbit']['block'])->toContain("reverb.orbit {\n    bind 10.44.0.3\n")
        ->toContain('reverse_proxy 127.0.0.1:9001')->toContain("@gateway remote_ip 10.44.0.2\n")->toContain('respond 403')
        // The Instance listener has the same site, admits only the VPN subnet, and keeps /internal for the Gateway only.
        ->and($blocks['reverb.orbit:8443']['block'])->toContain("reverb.orbit:8443 {\n    bind 10.44.0.3\n")
        ->toContain('@orbit_outside not remote_ip 10.44.0.0/24')->toContain('reverse_proxy 127.0.0.1:9001')
        ->toContain("@gateway remote_ip 10.44.0.2\n")->toContain('respond 403')
        // Nothing else binds port 8443 on the Node: a request with another Host or SNI there finds no site.
        ->and(collect($caddyfile->sites)->filter(fn ($site): bool => $site->port === 8443)->map(fn ($site): array => $site->hosts)->values()->all())
        ->toBe([['reverb.orbit']])
        ->and(preg_match_all('/^\S*:8443 \{$/m', $caddyfile->content, $addresses))->toBe(1)
        ->and($addresses[0])->toBe(['reverb.orbit:8443 {'])
        ->and($blocks['analytics.orbit']['block'])->not->toContain('8443');
});

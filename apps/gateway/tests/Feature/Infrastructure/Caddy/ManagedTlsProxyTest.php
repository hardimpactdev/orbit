<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Routes\CustomProxyUpstream;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteRemovalGuard;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentCaddyConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\Build\Sources\RouteCaddySiteSource;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\Route;

function managed_tls_proxy_fixture(): array
{
    $source = Node::query()->create(['name' => 'proxy-source', 'status' => LifecycleStatus::Active,
        'platform' => 'linux', 'public_ssh_host' => '10.44.0.3', 'wireguard_ip' => '10.44.0.3', 'user' => 'orbit']);
    $source->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $target = Node::query()->create(['name' => 'proxy-target', 'status' => LifecycleStatus::Active,
        'platform' => 'linux', 'public_ssh_host' => '10.44.0.15', 'wireguard_ip' => '10.44.0.15', 'user' => 'orbit']);
    NodeAccess::query()->create(['consumer_node_id' => $source->id, 'serving_node_id' => $target->id]);
    $route = Route::query()->create(['kind' => RouteKind::CustomProxy, 'node_id' => $source->id,
        'domain' => 'executor.orbit', 'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private, 'status' => RouteStatus::Pending]);
    $route->customProxy()->create(['node_id' => $target->id, 'upstream' => 'https://10.44.0.15:9417']);
    $route->update(['status' => RouteStatus::Active]);

    return [$source, $target, $route];
}

it('rebuilds a stored service relocation with verified TLS and streaming while preserving the serving Node', function (): void {
    [$source, $target, $route] = managed_tls_proxy_fixture();
    $renderer = new NodeCaddyfileRenderer([new RouteCaddySiteSource(new DevelopmentSiteRepository, new DevelopmentCaddyConfigRenderer)]);
    $first = $renderer->render($source);
    $second = $renderer->render($source->fresh());

    expect($first->buildable())->toBeTrue()
        ->and($second->content)->toBe($first->content)
        ->and($first->content)->toContain('reverse_proxy https://10.44.0.15:9417 {',
            'header_up Host executor.orbit', 'tls_server_name executor.orbit',
            'tls_trusted_ca_certs /usr/local/share/ca-certificates/orbit-managed-root-ca.crt',
            'versions 1.1', 'flush_interval -1', 'read_timeout 1h', 'response_header_timeout 1h')
        ->not->toContain('tls_insecure_skip_verify', 'reverse_proxy 127.0.0.1:9417')
        ->and($route->fresh()->node_id)->toBe($source->id)
        ->and($renderer->render($target)->sites)->toBe([]);
});

it('refuses a stored TLS relocation if the target loses its active address or existing access', function (string $change): void {
    [$source, $target] = managed_tls_proxy_fixture();
    match ($change) {
        'inactive' => $target->update(['status' => LifecycleStatus::Removing]),
        'address' => $target->update(['wireguard_ip' => '10.44.0.16']),
        'access' => NodeAccess::query()->delete(),
    };
    $renderer = new NodeCaddyfileRenderer([new RouteCaddySiteSource(new DevelopmentSiteRepository, new DevelopmentCaddyConfigRenderer)]);

    expect($renderer->render($source)->buildable())->toBeFalse();
})->with(['inactive', 'address', 'access']);

it('rejects malformed and unmanaged stored TLS endpoints', function (string $url): void {
    [$source, , $route] = managed_tls_proxy_fixture();
    $route->customProxy()->update(['upstream' => $url]);
    $renderer = new NodeCaddyfileRenderer([new RouteCaddySiteSource(new DevelopmentSiteRepository, new DevelopmentCaddyConfigRenderer)]);

    expect($renderer->render($source)->buildable())->toBeFalse();
})->with(['https://10.44.0.15', 'https://10.44.0.15:0', 'https://10.44.0.15:9417/',
    'https://10.44.0.15:9417/?x=y', 'https://user@10.44.0.15:9417', 'https://10.44.0.15:9417#fragment',
    'https://example.com:9417', 'https://1.1.1.1:9417', 'http://10.44.0.15:9417']);

it('keeps remote TLS URLs out of caller supplied custom proxy creation', function (): void {
    expect(fn () => CustomProxyUpstream::parse('https://10.44.0.15:9417'))->toThrow(ResourceOperationException::class);
});

it('protects both serving and backend Nodes from removal while the relocated Route serves', function (): void {
    [$source, $target] = managed_tls_proxy_fixture();
    foreach ([$source, $target] as $node) {
        expect(fn () => new RouteRemovalGuard()->assertNodeRemovable($node))
            ->toThrow(ResourceOperationException::class);
    }
});

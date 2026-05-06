<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Proxy\ProxyRouteRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

describe('ProxyRouteRenderer', function (): void {
    it('renders custom upstream routes as Caddy sites with Orbit TLS paths', function (): void {
        $node = Node::factory()->create(['role' => 'app']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'], 'upstream' => 'http://127.0.0.1:5173'],
        ]);

        $content = (new ProxyRouteRenderer)->render($route);

        expect($content)->toContain('vite.docs.test {')
            ->and($content)->toContain('tls /etc/orbit/certs/vite.docs.test.crt /etc/orbit/certs/vite.docs.test.key')
            ->and($content)->toContain('reverse_proxy http://127.0.0.1:5173')
            ->and((new ProxyRouteRenderer)->sourceHash($route))->toBe(hash('sha256', $content));
    });

    it('renders custom redirect routes with redirect codes', function (): void {
        $node = Node::factory()->create(['role' => 'app']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'old.docs.test',
            'owner_type' => 'custom',
            'kind' => 'redirect',
            'config' => ['target' => ['type' => 'redirect', 'value' => 'https://docs.test'], 'code' => 301],
        ]);

        $content = (new ProxyRouteRenderer)->render($route);

        expect($content)->toContain('old.docs.test {')
            ->and($content)->toContain('redir https://docs.test{uri} 301');
    });
});

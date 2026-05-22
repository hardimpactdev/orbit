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
    it('renders custom upstream routes as Caddy sites with Orbit TLS paths and normalizes host loopback for container reachability', function (): void {
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
            ->and($content)->toContain('reverse_proxy http://host.docker.internal:5173')
            ->and($content)->not->toContain('127.0.0.1')
            ->and((new ProxyRouteRenderer)->sourceHash($route))->toBe(hash('sha256', $content));
    });

    it('normalizes localhost upstreams to the orbit-caddy host gateway hostname', function (): void {
        $node = Node::factory()->create(['role' => 'app']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'mail.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['target' => ['type' => 'upstream', 'value' => 'http://localhost:8025'], 'upstream' => 'http://localhost:8025'],
        ]);

        $content = (new ProxyRouteRenderer)->render($route);

        expect($content)
            ->toContain('reverse_proxy http://host.docker.internal:8025')
            ->not->toContain('http://localhost')
            ->not->toContain('127.0.0.1');
    });

    it('leaves non-loopback upstreams untouched', function (): void {
        expect(ProxyRouteRenderer::normalizeHostLoopback('http://10.6.0.21:80'))->toBe('http://10.6.0.21:80')
            ->and(ProxyRouteRenderer::normalizeHostLoopback('https://example.com:443/api'))->toBe('https://example.com:443/api')
            ->and(ProxyRouteRenderer::normalizeHostLoopback('http://127.0.0.1.example.com:80'))->toBe('http://127.0.0.1.example.com:80');
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

    it('accepts numeric string redirect codes in the 3xx range', function (): void {
        $node = Node::factory()->create(['role' => 'app']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'old.docs.test',
            'owner_type' => 'custom',
            'kind' => 'redirect',
            'config' => ['target' => ['type' => 'redirect', 'value' => 'https://docs.test'], 'code' => '302'],
        ]);

        $content = (new ProxyRouteRenderer)->render($route);

        expect($content)->toContain('redir https://docs.test{uri} 302');
    });

    it('rejects non numeric redirect codes', function (): void {
        $node = Node::factory()->create(['role' => 'app']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'old.docs.test',
            'owner_type' => 'custom',
            'kind' => 'redirect',
            'config' => ['target' => ['type' => 'redirect', 'value' => 'https://docs.test'], 'code' => 'abc'],
        ]);

        (new ProxyRouteRenderer)->render($route);
    })->throws(RuntimeException::class, "Proxy route 'old.docs.test' has an invalid redirect code.");

    it('rejects redirect codes outside the 3xx range', function (): void {
        $node = Node::factory()->create(['role' => 'app']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'old.docs.test',
            'owner_type' => 'custom',
            'kind' => 'redirect',
            'config' => ['target' => ['type' => 'redirect', 'value' => 'https://docs.test'], 'code' => 200],
        ]);

        (new ProxyRouteRenderer)->render($route);
    })->throws(RuntimeException::class, "Proxy route 'old.docs.test' has an invalid redirect code.");

    it('renders ingress routes through the router upstream with forwarded headers', function (): void {
        $ingress = Node::factory()->create(['name' => 'edge-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $ingress->id,
            'domain' => 'example.com',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'router_upstream' => [
                    'node_id' => 12,
                    'node' => 'gateway-1',
                    'url' => 'http://10.6.0.2:80',
                ],
                'router_backend_pool' => [
                    ['node_id' => 42, 'node' => 'web-1', 'url' => 'http://10.6.0.21:80'],
                ],
                'backend_artifacts' => [
                    [
                        'node_id' => 42,
                        'domain' => 'example.com',
                        'bind' => '10.6.0.21',
                        'document_root' => '/home/orbit/sites/example/current/public',
                        'php_socket' => '/home/orbit/.config/orbit/php/example.sock',
                        'source_hash' => str_repeat('a', 64),
                    ],
                ],
                'tls' => [
                    'cert_path' => '/home/orbit/.config/orbit/certs/example.com.crt',
                    'key_path' => '/home/orbit/.config/orbit/certs/example.com.key',
                ],
            ],
        ]);

        $content = (new ProxyRouteRenderer)->renderIngress($route);

        expect($content)->toBe(<<<'CADDY'
example.com {
    tls /home/orbit/.config/orbit/certs/example.com.crt /home/orbit/.config/orbit/certs/example.com.key
    encode gzip

    reverse_proxy http://10.6.0.2:80 {
        header_up Host {host}
        header_up X-Forwarded-Host {host}
        header_up X-Forwarded-Proto {scheme}
    }
}

CADDY);
    });

    it('renders router routes with private backend pools and forwarded headers', function (): void {
        $router = Node::factory()->create(['name' => 'gateway-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $router->id,
            'domain' => 'example.com',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'router_upstream' => [
                    'node_id' => $router->id,
                    'node' => 'gateway-1',
                    'url' => 'http://10.6.0.2:80',
                ],
                'router_backend_pool' => [
                    [
                        'node_id' => 42,
                        'node' => 'web-1',
                        'url' => 'http://10.6.0.21:80',
                    ],
                ],
            ],
        ]);

        $content = (new ProxyRouteRenderer)->renderRouterRoute($route);

        expect($content)->toBe(<<<'CADDY'
http://example.com {
    encode gzip

    reverse_proxy http://10.6.0.21:80 {
        lb_policy first
        header_up Host {host}
        header_up X-Forwarded-Host {host}
        header_up X-Forwarded-Proto {http.request.header.X-Forwarded-Proto}
    }
}

CADDY);
    });

    it('rejects router routes whose upstream host is not a valid IP address', function (): void {
        $router = Node::factory()->create(['name' => 'gateway-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $router->id,
            'domain' => 'example.com',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'router_upstream' => [
                    'node_id' => $router->id,
                    'node' => 'gateway-1',
                    'url' => 'http://gateway:80',
                ],
                'router_backend_pool' => [
                    [
                        'node_id' => 42,
                        'node' => 'web-1',
                        'url' => 'http://10.6.0.21:80',
                    ],
                ],
            ],
        ]);

        (new ProxyRouteRenderer)->renderRouterRoute($route);
    })->throws(RuntimeException::class, "Proxy route 'example.com' backend artifact has an invalid bind address.");

    it('rejects ingress routes with invalid router upstream urls', function (): void {
        $ingress = Node::factory()->create(['name' => 'edge-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $ingress->id,
            'domain' => 'example.com',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'router_upstream' => [
                    'node_id' => 12,
                    'node' => 'gateway-1',
                    'url' => "http://10.6.0.2:80\nmalicious",
                ],
                'tls' => [
                    'cert_path' => '/home/orbit/.config/orbit/certs/example.com.crt',
                    'key_path' => '/home/orbit/.config/orbit/certs/example.com.key',
                ],
            ],
        ]);

        (new ProxyRouteRenderer)->renderIngress($route);
    })->throws(RuntimeException::class, 'Proxy route router upstream requires a valid http or https url.');

    it('rejects ingress routes with unsafe tls paths', function (): void {
        $ingress = Node::factory()->create(['name' => 'edge-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $ingress->id,
            'domain' => 'example.com',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'router_upstream' => [
                    'node_id' => 12,
                    'node' => 'gateway-1',
                    'url' => 'http://10.6.0.2:80',
                ],
                'tls' => [
                    'cert_path' => "relative/example.com.crt\n",
                    'key_path' => '/home/orbit/.config/orbit/certs/example.com.key',
                ],
            ],
        ]);

        (new ProxyRouteRenderer)->renderIngress($route);
    })->throws(RuntimeException::class, "Proxy route 'example.com' has an invalid TLS cert path.");

    it('renders private backend routes whose artifact paths fall under orbit-caddy mounted host roots', function (): void {
        $appNode = Node::factory()->create(['name' => 'web-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'example.com',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'backend_artifacts' => [
                    [
                        'node_id' => $appNode->id,
                        'domain' => 'example.com',
                        'bind' => '10.6.0.21',
                        'document_root' => '/home/orbit/sites/example/current/public',
                        'php_socket' => '/home/orbit/.config/orbit/php/example.sock',
                        'source_hash' => str_repeat('b', 64),
                    ],
                ],
            ],
        ]);

        $content = (new ProxyRouteRenderer)->renderPrivateBackend($route, $route->config['backend_artifacts'][0]);

        expect($content)->toBe(<<<'CADDY'
http://example.com:8081 {
    root * /home/orbit/sites/example/current/public
    encode gzip

    import security_headers
    import profiling_headers
    import path_blocking_public_root
    import security_txt
    import cache_headers
    php_fastcgi unix//home/orbit/.config/orbit/php/example.sock
    file_server
}

CADDY);
    });

    it('rejects private backend routes with invalid bind addresses', function (): void {
        $appNode = Node::factory()->create(['name' => 'web-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'example.com',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'backend_artifacts' => [
                    [
                        'node_id' => $appNode->id,
                        'domain' => 'example.com',
                        'bind' => '10.6.0.21 bad',
                        'document_root' => '/home/orbit/sites/example/current/public',
                        'php_socket' => '/home/orbit/.config/orbit/php/example.sock',
                        'source_hash' => str_repeat('b', 64),
                    ],
                ],
            ],
        ]);

        (new ProxyRouteRenderer)->renderPrivateBackend($route, $route->config['backend_artifacts'][0]);
    })->throws(RuntimeException::class, "Proxy route 'example.com' backend artifact has an invalid bind address.");

    it('rejects private backend routes with unsafe document root paths', function (): void {
        $appNode = Node::factory()->create(['name' => 'web-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'example.com',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'backend_artifacts' => [
                    [
                        'node_id' => $appNode->id,
                        'domain' => 'example.com',
                        'bind' => '10.6.0.21',
                        'document_root' => "relative/path\n",
                        'php_socket' => '/home/orbit/.config/orbit/php/example.sock',
                        'source_hash' => str_repeat('b', 64),
                    ],
                ],
            ],
        ]);

        (new ProxyRouteRenderer)->renderPrivateBackend($route, $route->config['backend_artifacts'][0]);
    })->throws(RuntimeException::class, "Proxy route 'example.com' backend artifact has an invalid document root.");

    it('rejects private backend routes with unsafe php socket paths', function (): void {
        $appNode = Node::factory()->create(['name' => 'web-1']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'example.com',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'backend_artifacts' => [
                    [
                        'node_id' => $appNode->id,
                        'domain' => 'example.com',
                        'bind' => '10.6.0.21',
                        'document_root' => '/home/orbit/sites/example/current/public',
                        'php_socket' => "/home/orbit/.config/orbit/php/example.sock\r\n",
                        'source_hash' => str_repeat('b', 64),
                    ],
                ],
            ],
        ]);

        (new ProxyRouteRenderer)->renderPrivateBackend($route, $route->config['backend_artifacts'][0]);
    })->throws(RuntimeException::class, "Proxy route 'example.com' backend artifact has an invalid PHP socket path.");
});

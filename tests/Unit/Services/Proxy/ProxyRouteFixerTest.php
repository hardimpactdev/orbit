<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\ProxyRoute;
use App\Services\Ca\OrbitCaService;
use App\Services\Proxy\ProxyRouteFixer;
use App\Services\Proxy\ProxyRouteRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\SiteCertificateInstallerFake;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

describe('ProxyRouteFixer', function (): void {
    it('re-applies missing custom proxy routes from gateway intent', function (): void {
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'source_hash' => str_repeat('0', 64),
            'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'], 'upstream' => 'http://127.0.0.1:5173'],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;
        $renderer = new ProxyRouteRenderer;

        $action = (new ProxyRouteFixer($shell, $renderer, new ProxyFixerFakeCa, new SiteCertificateInstallerFake))->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.route_missing',
            kind: DriftKind::Missing,
            summary: 'missing',
        ));
        $caddySite = base64_decode((string) str($shell->scripts[0])->match("/printf %s\\s+'([^']+)'/")->toString(), true);

        expect($action)->toMatchArray([
            'family' => 'proxy',
            'node' => 'app-1',
            'key' => 'proxy.route_missing',
            'status' => 'completed',
        ])
            ->and($shell->scripts[0])->toContain('/etc/caddy/sites/vite.docs.test.caddy')
            ->and($caddySite)->toContain('reverse_proxy http://127.0.0.1:5173')
            ->and($shell->scripts[0])->toContain('sudo systemctl reload caddy')
            ->and($route->refresh()->source_hash)->toBe(hash('sha256', $caddySite))
            ->and($route->refresh()->source_hash)->toBe($renderer->sourceHash($route));
    });

    it('re-applies ingress routes and names the public side', function (): void {
        $edge = Node::factory()->create(['name' => 'edge-1', 'role' => 'control']);
        $router = Node::factory()->create(['name' => 'gateway-1', 'role' => 'gateway']);
        $backend = Node::factory()->create(['name' => 'web-1', 'role' => 'control']);
        NodeRoleAssignment::factory()->create(['node_id' => $edge->id, 'role' => 'ingress', 'status' => 'active']);
        NodeRoleAssignment::factory()->create(['node_id' => $router->id, 'role' => 'router', 'status' => 'active']);
        NodeRoleAssignment::factory()->create(['node_id' => $backend->id, 'role' => 'app-production', 'status' => 'active']);
        $app = App::factory()->create(['node_id' => $backend->id, 'name' => 'docs']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'source_hash' => str_repeat('0', 64),
            'config' => [
                'placement' => 'ingress',
                'router_upstream' => ['node_id' => $router->id, 'node' => 'gateway-1', 'url' => 'http://10.6.0.2:80'],
                'router_artifact' => ['node_id' => $router->id, 'node' => 'gateway-1', 'source_hash' => str_repeat('c', 64)],
                'router_backend_pool' => [['node_id' => $backend->id, 'node' => 'web-1', 'url' => 'http://10.6.0.21:80']],
                'backend_artifacts' => [[
                    'node_id' => $backend->id,
                    'bind' => '10.6.0.21',
                    'document_root' => '/home/orbit/apps/docs/public',
                    'php_socket' => '/home/orbit/.config/orbit/php/docs.sock',
                    'source_hash' => str_repeat('b', 64),
                ]],
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = (new ProxyRouteFixer($shell, new ProxyRouteRenderer, new ProxyFixerFakeCa, new SiteCertificateInstallerFake))->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.public_route_missing',
            kind: DriftKind::Missing,
            summary: 'missing',
        ));
        $caddySite = base64_decode((string) str($shell->scripts[0])->match("/printf %s\\s+'([^']+)'/")->toString(), true);

        expect($action['summary'])->toBe('Re-applied public proxy route docs.test from gateway intent.')
            ->and($action['node'])->toBe('edge-1')
            ->and($shell->nodes[0]->is($edge))->toBeTrue()
            ->and($shell->scripts[0])->toContain('/etc/caddy/sites/docs.test.caddy')
            ->and($caddySite)->toContain('reverse_proxy http://10.6.0.2:80')
            ->and($route->refresh()->source_hash)->toBe(hash('sha256', $caddySite));
    });

    it('re-applies router routes and names the router side', function (): void {
        $edge = Node::factory()->create(['name' => 'edge-1', 'role' => 'control']);
        $router = Node::factory()->create(['name' => 'gateway-1', 'role' => 'gateway']);
        $backend = Node::factory()->create(['name' => 'web-1', 'role' => 'control']);
        NodeRoleAssignment::factory()->create(['node_id' => $edge->id, 'role' => 'ingress', 'status' => 'active']);
        NodeRoleAssignment::factory()->create(['node_id' => $router->id, 'role' => 'router', 'status' => 'active']);
        NodeRoleAssignment::factory()->create(['node_id' => $backend->id, 'role' => 'app-production', 'status' => 'active']);
        $app = App::factory()->create(['node_id' => $backend->id, 'name' => 'docs']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'router_upstream' => ['node_id' => $router->id, 'node' => 'gateway-1', 'url' => 'http://10.6.0.2:80'],
                'router_artifact' => ['node_id' => $router->id, 'node' => 'gateway-1', 'source_hash' => str_repeat('c', 64)],
                'router_backend_pool' => [['node_id' => $backend->id, 'node' => 'web-1', 'url' => 'http://10.6.0.21:80']],
                'backend_artifacts' => [[
                    'node_id' => $backend->id,
                    'bind' => '10.6.0.21',
                    'document_root' => '/home/orbit/apps/docs/public',
                    'php_socket' => '/home/orbit/.config/orbit/php/docs.sock',
                    'source_hash' => str_repeat('b', 64),
                ]],
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = (new ProxyRouteFixer($shell, new ProxyRouteRenderer, new ProxyFixerFakeCa, new SiteCertificateInstallerFake))->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.router_route_missing',
            kind: DriftKind::Missing,
            summary: 'missing',
        ));
        $caddySite = base64_decode((string) str($shell->scripts[0])->match("/printf %s\\s+'([^']+)'/")->toString(), true);

        expect($action['summary'])->toBe('Re-applied private router route docs.test from gateway intent.')
            ->and($action['node'])->toBe('gateway-1')
            ->and($shell->nodes[0]->is($router))->toBeTrue()
            ->and($shell->scripts[0])->toContain('/etc/caddy/sites/docs.test.caddy')
            ->and($caddySite)->toContain('bind 10.6.0.2')
            ->and($caddySite)->toContain('reverse_proxy http://10.6.0.21:80')
            ->and($route->refresh()->config['router_artifact']['source_hash'])->toBe(hash('sha256', $caddySite));
    });

    it('re-applies private backend artifacts and names the backend side', function (): void {
        $edge = Node::factory()->create(['name' => 'edge-1', 'role' => 'control']);
        $backend = Node::factory()->create(['name' => 'web-1', 'role' => 'control']);
        NodeRoleAssignment::factory()->create(['node_id' => $edge->id, 'role' => 'ingress', 'status' => 'active']);
        NodeRoleAssignment::factory()->create(['node_id' => $backend->id, 'role' => 'app-production', 'status' => 'active']);
        $app = App::factory()->create(['node_id' => $backend->id, 'name' => 'docs']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'router_backend_pool' => [['node_id' => $backend->id, 'node' => 'web-1', 'url' => 'http://10.6.0.21:80']],
                'backend_artifacts' => [[
                    'node_id' => $backend->id,
                    'bind' => '10.6.0.21',
                    'document_root' => '/home/orbit/apps/docs/public',
                    'php_socket' => '/home/orbit/.config/orbit/php/docs.sock',
                    'source_hash' => str_repeat('b', 64),
                ]],
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = (new ProxyRouteFixer($shell, new ProxyRouteRenderer, new ProxyFixerFakeCa, new SiteCertificateInstallerFake))->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.backend_route_mismatch',
            kind: DriftKind::Divergent,
            summary: 'mismatch',
            detail: ['backend_node_id' => $backend->id],
        ));
        $caddySite = base64_decode((string) str($shell->scripts[0])->match("/printf %s\\s+'([^']+)'/")->toString(), true);

        expect($action['summary'])->toBe('Re-applied private backend route docs.test on web-1 from gateway intent.')
            ->and($action['node'])->toBe('web-1')
            ->and($shell->nodes[0]->is($backend))->toBeTrue()
            ->and($shell->scripts[0])->toContain('/etc/caddy/sites/docs.test.backend.caddy')
            ->and($caddySite)->toContain('bind 10.6.0.21')
            ->and($caddySite)->toContain('php_fastcgi unix//home/orbit/.config/orbit/php/docs.sock');
    });

    it('does not repair backend routes without an explicit matching backend node id', function (array $detail): void {
        $edge = Node::factory()->create(['name' => 'edge-1', 'role' => 'control']);
        $backend = Node::factory()->create(['name' => 'web-1', 'role' => 'control']);
        $otherBackend = Node::factory()->create(['name' => 'web-2', 'role' => 'control']);
        NodeRoleAssignment::factory()->create(['node_id' => $edge->id, 'role' => 'ingress', 'status' => 'active']);
        NodeRoleAssignment::factory()->create(['node_id' => $backend->id, 'role' => 'app-production', 'status' => 'active']);
        NodeRoleAssignment::factory()->create(['node_id' => $otherBackend->id, 'role' => 'app-production', 'status' => 'active']);
        $app = App::factory()->create(['node_id' => $backend->id, 'name' => 'docs']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $edge->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'placement' => 'ingress',
                'router_backend_pool' => [['node_id' => $backend->id, 'node' => 'web-1', 'url' => 'http://10.6.0.21:80']],
                'backend_artifacts' => [[
                    'node_id' => $backend->id,
                    'bind' => '10.6.0.21',
                    'document_root' => '/home/orbit/apps/docs/public',
                    'php_socket' => '/home/orbit/.config/orbit/php/docs.sock',
                    'source_hash' => str_repeat('b', 64),
                ]],
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = (new ProxyRouteFixer($shell, new ProxyRouteRenderer, new ProxyFixerFakeCa, new SiteCertificateInstallerFake))->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.backend_route_missing',
            kind: DriftKind::Missing,
            summary: 'missing',
            detail: $detail,
        ));

        expect($action)->toBeNull()
            ->and($shell->scripts)->toBe([])
            ->and($shell->nodes)->toBe([]);
    })->with([
        'missing backend node id' => [[]],
        'invalid backend node id' => [['backend_node_id' => 'web-1']],
        'nonmatching backend node id' => [['backend_node_id' => 999_999]],
    ]);

    it('repairs missing Orbit-managed TLS material for custom proxy routes', function (): void {
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'], 'upstream' => 'http://127.0.0.1:5173'],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;

        $action = (new ProxyRouteFixer($shell, new ProxyRouteRenderer, new ProxyFixerFakeCa, new SiteCertificateInstallerFake))->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.tls_missing',
            kind: DriftKind::Missing,
            summary: 'tls missing',
        ));

        expect($action)->toMatchArray([
            'family' => 'proxy',
            'node' => 'app-1',
            'key' => 'proxy.tls_missing',
            'status' => 'completed',
        ])
            ->and($shell->scripts[0])->toContain('/etc/orbit/certs/vite.docs.test.crt')
            ->and($shell->scripts[0])->toContain('/etc/orbit/certs/vite.docs.test.key')
            ->and($shell->scripts[0])->toContain("sudo chmod 0600 '/etc/orbit/certs/vite.docs.test.key'")
            ->and($shell->scripts[0])->toContain('systemctl show caddy -p Group --value')
            ->and($shell->scripts[0])->toContain('systemctl show caddy -p User --value')
            ->and($shell->scripts[0])->toContain('id -gn "$orbit_caddy_user"')
            ->and($shell->scripts[0])->toContain('getent group caddy')
            ->and($shell->scripts[0])->toContain("sudo chgrp \"\$orbit_caddy_group\" '/etc/orbit/certs/vite.docs.test.key'")
            ->and($shell->scripts[0])->toContain("sudo chmod 0640 '/etc/orbit/certs/vite.docs.test.key'")
            ->and($shell->scripts[0])->toContain('sudo systemctl reload caddy');
    });

    it('re-applies app proxy routes from gateway intent', function (): void {
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'document_root' => 'public',
        ]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'source_hash' => str_repeat('0', 64),
            'config' => [
                'document_root' => '/home/orbit/apps/docs/public',
                'php_socket' => '/home/orbit/.config/orbit/php/docs.sock',
                'tls' => 'internal',
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;
        $certificates = new SiteCertificateInstallerFake;

        $action = (new ProxyRouteFixer($shell, new ProxyRouteRenderer, new ProxyFixerFakeCa, $certificates))->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.route_mismatch',
            kind: DriftKind::Divergent,
            summary: 'mismatch',
        ));
        $caddySite = base64_decode((string) str($shell->scripts[0])->match("/printf %s\\s+'([^']+)'/")->toString(), true);

        expect($action)->toMatchArray([
            'family' => 'proxy',
            'node' => 'app-1',
            'key' => 'proxy.route_mismatch',
            'status' => 'completed',
        ])
            ->and($shell->scripts[0])->toContain('/etc/caddy/sites/docs.test.caddy')
            ->and($caddySite)->toContain('tls /etc/orbit/certs/docs.test.crt /etc/orbit/certs/docs.test.key')
            ->and($caddySite)->toContain('php_fastcgi unix//home/orbit/.config/orbit/php/docs.sock')
            ->and($certificates->hosts)->toBe(['docs.test'])
            ->and($route->refresh()->source_hash)->toBe(hash('sha256', $caddySite))
            ->and($route->refresh()->source_hash)->toBe((new ProxyRouteRenderer)->sourceHash($route));
    });

    it('repairs app route TLS through the site certificate installer', function (): void {
        $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
        $app = App::factory()->create([
            'node_id' => $node->id,
            'name' => 'docs',
            'document_root' => 'public',
        ]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => [
                'document_root' => '/home/orbit/apps/docs/public',
                'php_socket' => '/home/orbit/.config/orbit/php/docs.sock',
                'tls' => [
                    'cert_path' => '/home/orbit/.config/orbit/certs/docs.test.crt',
                    'key_path' => '/home/orbit/.config/orbit/certs/docs.test.key',
                ],
            ],
        ]);
        $shell = new ProxyFixerRecordingRemoteShell;
        $certificates = new SiteCertificateInstallerFake;

        $action = (new ProxyRouteFixer($shell, new ProxyRouteRenderer, new ProxyFixerFakeCa, $certificates))->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.tls_missing',
            kind: DriftKind::Missing,
            summary: 'tls missing',
        ));

        expect($action)->toMatchArray([
            'family' => 'proxy',
            'node' => 'app-1',
            'key' => 'proxy.tls_missing',
            'status' => 'completed',
        ])
            ->and($certificates->hosts)->toBe(['docs.test'])
            ->and($shell->scripts)->toBe([]);
    });
});

final readonly class ProxyFixerFakeCa extends OrbitCaService
{
    /** @return array{cert: string, key: string} */
    public function issueLeaf(string $host, array $additionalSans = []): array
    {
        $dir = sys_get_temp_dir().'/orbit-proxy-fixer-ca';

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $cert = "{$dir}/{$host}.crt";
        $key = "{$dir}/{$host}.key";

        file_put_contents($cert, "fake-cert-for-{$host}");
        file_put_contents($key, "fake-key-for-{$host}");

        return ['cert' => $cert, 'key' => $key];
    }
}

final class ProxyFixerRecordingRemoteShell implements RemoteShell
{
    /** @var list<Node> */
    public array $nodes = [];

    /** @var list<string> */
    public array $scripts = [];

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->nodes[] = $node;
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

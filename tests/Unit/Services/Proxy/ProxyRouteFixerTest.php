<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Doctor\DriftEntry;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Ca\OrbitCaService;
use App\Services\Proxy\ProxyRouteFixer;
use App\Services\Proxy\ProxyRouteRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $action = (new ProxyRouteFixer($shell, $renderer, new ProxyFixerFakeCa))->fix($route, new DriftEntry(
            family: 'proxy',
            key: 'proxy.route_missing',
            kind: DriftKind::Missing,
            summary: 'missing',
        ));

        expect($action)->toMatchArray([
            'family' => 'proxy',
            'node' => 'app-1',
            'key' => 'proxy.route_missing',
            'status' => 'completed',
        ])
            ->and($shell->scripts[0])->toContain('/etc/caddy/sites/vite.docs.test.caddy')
            ->and($shell->scripts[0])->toContain('reverse_proxy http://127.0.0.1:5173')
            ->and($shell->scripts[0])->toContain('sudo systemctl reload caddy')
            ->and($route->refresh()->source_hash)->toBe($renderer->sourceHash($route));
    });

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

        $action = (new ProxyRouteFixer($shell, new ProxyRouteRenderer, new ProxyFixerFakeCa))->fix($route, new DriftEntry(
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
            ->and($shell->scripts[0])->toContain('sudo systemctl reload caddy');
    });
});

final readonly class ProxyFixerFakeCa extends OrbitCaService
{
    /** @return array{cert: string, key: string} */
    public function issueLeaf(string $host): array
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
    /** @var list<string> */
    public array $scripts = [];

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

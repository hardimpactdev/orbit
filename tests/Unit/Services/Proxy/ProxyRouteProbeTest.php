<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Doctor\ProbeSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Proxy\ProxyRouteProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function proxyProbeIssue(array $drift, string $key): mixed
{
    return collect($drift)->first(fn ($entry): bool => $entry->key === $key);
}

function createProxyProbeGatewayAssignmentNode(): Node
{
    $node = Node::factory()->create(['role' => 'control', 'status' => 'active']);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
        'settings' => [],
    ]);

    return $node;
}

describe('ProxyRouteProbe interface', function (): void {
    it('has key and label', function (): void {
        $probe = new ProxyRouteProbe;

        expect($probe->key())->toBe('proxy')
            ->and($probe->label())->toBe('Proxy');
    });

    it('returns an empty foundation snapshot before live backend probing is added', function (): void {
        $route = new ProxyRoute(['domain' => 'docs.test']);

        expect((new ProxyRouteProbe)->introspect($route)->isEmpty())->toBeTrue();
    });
});

describe('proxy registry probe foundation', function (): void {
    it('passes complete custom proxy routes on active app nodes', function (): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'], 'upstream' => 'http://127.0.0.1:5173'],
        ]);

        $drift = (new ProxyRouteProbe)->diff($route, new ProbeSnapshot([]));

        expect($drift)->toBe([]);
    });

    it('passes complete custom proxy routes on active gateway role assignments', function (): void {
        $node = createProxyProbeGatewayAssignmentNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['target' => ['type' => 'upstream', 'value' => 'http://127.0.0.1:5173'], 'upstream' => 'http://127.0.0.1:5173'],
        ]);

        $drift = (new ProxyRouteProbe)->diff($route, new ProbeSnapshot([]));

        expect($drift)->toBe([]);
    });

    it('detects incomplete route records', function (): void {
        $node = createTestAppHostNode();
        $id = DB::table('proxy_routes')->insertGetId([
            'node_id' => $node->id,
            'domain' => 'broken.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'source_hash' => str_repeat('0', 64),
            'config' => json_encode([], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $route = ProxyRoute::findOrFail($id);
        $drift = (new ProxyRouteProbe)->diff($route, new ProbeSnapshot([]));

        expect(proxyProbeIssue($drift, 'proxy.record_incomplete')?->kind)->toBe(DriftKind::Missing);
    });

    it('requires app owners to resolve', function (): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => null,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
        ]);

        $drift = (new ProxyRouteProbe)->diff($route, new ProbeSnapshot([]));

        expect(proxyProbeIssue($drift, 'proxy.owner_invalid')?->kind)->toBe(DriftKind::Divergent);
    });

    it('requires workspace owners to resolve', function (): void {
        $node = createTestAppHostNode();
        $app = App::factory()->create(['node_id' => $node->id]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'workspace_id' => null,
            'domain' => 'feature.docs.test',
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);

        $drift = (new ProxyRouteProbe)->diff($route, new ProbeSnapshot([]));

        expect(proxyProbeIssue($drift, 'proxy.owner_invalid')?->kind)->toBe(DriftKind::Divergent);
    });

    it('requires active gateway or app serving nodes', function (array $nodeState): void {
        $node = Node::factory()->create($nodeState);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['upstream' => 'http://127.0.0.1:5173'],
        ]);

        $drift = (new ProxyRouteProbe)->diff($route, new ProbeSnapshot([]));

        expect(proxyProbeIssue($drift, 'proxy.node_invalid')?->kind)->toBe(DriftKind::Divergent);
    })->with([
        'control node' => [['role' => 'control', 'status' => 'active']],
        'inactive app node' => [['role' => 'app', 'status' => 'inactive']],
    ]);

    it('detects custom route conflicts with app domains', function (): void {
        $node = createTestAppHostNode();
        App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'domain' => 'docs.test']);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'docs.test',
            'owner_type' => 'custom',
            'kind' => 'proxy',
            'config' => ['upstream' => 'http://127.0.0.1:5173'],
        ]);

        $drift = (new ProxyRouteProbe)->diff($route, new ProbeSnapshot([]));

        expect(proxyProbeIssue($drift, 'proxy.domain_conflict')?->kind)->toBe(DriftKind::Divergent)
            ->and(proxyProbeIssue($drift, 'proxy.domain_conflict')?->detail)->toMatchArray([
                'owner_type' => 'app',
                'owner_name' => 'docs',
            ]);
    });

    it('accepts resolved app and workspace owners', function (): void {
        $node = createTestAppHostNode();
        $app = App::factory()->create(['node_id' => $node->id]);
        $workspace = Workspace::factory()->create(['app_id' => $app->id]);

        $appRoute = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'domain' => 'docs.test',
            'owner_type' => 'app',
            'kind' => 'app',
        ]);
        $workspaceRoute = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'workspace_id' => $workspace->id,
            'domain' => 'feature.docs.test',
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);

        expect((new ProxyRouteProbe)->diff($appRoute, new ProbeSnapshot([])))->toBe([])
            ->and((new ProxyRouteProbe)->diff($workspaceRoute, new ProbeSnapshot([])))->toBe([]);
    });
});

describe('proxy backend and TLS reality', function (): void {
    it('introspects backend route and TLS material for the selected route', function (): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'source_hash' => str_repeat('a', 64),
        ]);
        $shell = new ProxyProbeRecordingRemoteShell(
            "1\t".str_repeat('a', 64)."\t/etc/orbit/certs/vite.docs.test.crt\t/etc/orbit/certs/vite.docs.test.key\t1\t1\n",
        );

        $snapshot = (new ProxyRouteProbe($shell))->introspect($route);

        expect($snapshot->get('vite.docs.test'))->toMatchArray([
            'route_exists' => true,
            'route_hash' => str_repeat('a', 64),
            'cert_path' => '/etc/orbit/certs/vite.docs.test.crt',
            'key_path' => '/etc/orbit/certs/vite.docs.test.key',
            'cert_exists' => true,
            'key_exists' => true,
        ])
            ->and($shell->nodes[0]->is($node))->toBeTrue()
            ->and($shell->options[0]['env']['ORBIT_PROXY_DOMAIN'])->toBe('vite.docs.test');
    });

    it('detects missing backend route reality', function (): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
        ]);

        $snapshot = new ProbeSnapshot([
            'vite.docs.test' => [
                'route_exists' => false,
            ],
        ]);

        $drift = (new ProxyRouteProbe)->diff($route, $snapshot);

        expect(proxyProbeIssue($drift, 'proxy.route_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('detects backend route hash mismatch', function (): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'source_hash' => str_repeat('a', 64),
        ]);

        $snapshot = new ProbeSnapshot([
            'vite.docs.test' => [
                'route_exists' => true,
                'route_hash' => str_repeat('b', 64),
            ],
        ]);

        $drift = (new ProxyRouteProbe)->diff($route, $snapshot);

        expect(proxyProbeIssue($drift, 'proxy.route_mismatch')?->kind)->toBe(DriftKind::Divergent)
            ->and(proxyProbeIssue($drift, 'proxy.route_mismatch')?->detail)->toMatchArray([
                'expected_hash' => str_repeat('a', 64),
                'observed_hash' => str_repeat('b', 64),
            ]);
    });

    it('detects missing Orbit-managed TLS material', function (): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'source_hash' => str_repeat('a', 64),
        ]);

        $snapshot = new ProbeSnapshot([
            'vite.docs.test' => [
                'route_exists' => true,
                'route_hash' => str_repeat('a', 64),
                'cert_exists' => false,
                'key_exists' => true,
            ],
        ]);

        $drift = (new ProxyRouteProbe)->diff($route, $snapshot);

        expect(proxyProbeIssue($drift, 'proxy.tls_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('detects mismatched Orbit-managed TLS paths', function (): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'source_hash' => str_repeat('a', 64),
        ]);

        $snapshot = new ProbeSnapshot([
            'vite.docs.test' => [
                'route_exists' => true,
                'route_hash' => str_repeat('a', 64),
                'cert_exists' => true,
                'key_exists' => true,
                'cert_path' => '/tmp/wrong.crt',
                'key_path' => '/tmp/wrong.key',
            ],
        ]);

        $drift = (new ProxyRouteProbe)->diff($route, $snapshot);

        expect(proxyProbeIssue($drift, 'proxy.tls_mismatch')?->kind)->toBe(DriftKind::Divergent);
    });

    it('skips TLS drift for externally managed routes', function (): void {
        $node = createTestAppHostNode();
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'source_hash' => str_repeat('a', 64),
            'config' => [
                'upstream' => 'http://127.0.0.1:5173',
                'tls' => ['managed_by' => 'external'],
            ],
        ]);

        $snapshot = new ProbeSnapshot([
            'vite.docs.test' => [
                'route_exists' => true,
                'route_hash' => str_repeat('a', 64),
                'cert_exists' => false,
                'key_exists' => false,
            ],
        ]);

        $drift = (new ProxyRouteProbe)->diff($route, $snapshot);

        expect(proxyProbeIssue($drift, 'proxy.tls_missing'))->toBeNull()
            ->and(proxyProbeIssue($drift, 'proxy.tls_mismatch'))->toBeNull();
    });

    it('skips TLS drift for internal TLS app and workspace routes', function (string $ownerType, string $kind): void {
        $node = createTestAppHostNode();
        $app = App::factory()->create(['node_id' => $node->id]);
        $workspace = Workspace::factory()->create(['app_id' => $app->id]);
        $route = ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'app_id' => $app->id,
            'workspace_id' => $ownerType === 'workspace' ? $workspace->id : null,
            'domain' => "{$kind}.docs.test",
            'owner_type' => $ownerType,
            'kind' => $kind,
            'source_hash' => str_repeat('a', 64),
            'config' => [
                'document_root' => '/home/orbit/apps/docs/public',
                'php_socket' => '/home/orbit/.config/orbit/php/docs.sock',
                'tls' => 'internal',
            ],
        ]);

        $snapshot = new ProbeSnapshot([
            $route->domain => [
                'route_exists' => true,
                'route_hash' => str_repeat('a', 64),
                'cert_exists' => false,
                'key_exists' => false,
                'cert_path' => '',
                'key_path' => '',
            ],
        ]);

        $drift = (new ProxyRouteProbe)->diff($route, $snapshot);

        expect(proxyProbeIssue($drift, 'proxy.tls_missing'))->toBeNull()
            ->and(proxyProbeIssue($drift, 'proxy.tls_mismatch'))->toBeNull();
    })->with([
        'app route' => ['app', 'app'],
        'workspace route' => ['workspace', 'workspace'],
    ]);
});

describe('proxy node-level introspection', function (): void {
    it('introspects all caddy sites on a node', function (): void {
        $node = createTestAppHostNode();
        $shell = new ProxyProbeRecordingRemoteShell(
            "vite.docs.test\t".str_repeat('a', 64)."\t/etc/orbit/certs/vite.docs.test.crt\t/etc/orbit/certs/vite.docs.test.key\t1\t1\n"
            ."api.docs.test\t".str_repeat('b', 64)."\t/etc/orbit/certs/api.docs.test.crt\t/etc/orbit/certs/api.docs.test.key\t1\t1\n",
        );

        $snapshot = (new ProxyRouteProbe($shell))->introspectNode($node);

        expect($snapshot->keys())->toHaveCount(2)
            ->and($snapshot->get('vite.docs.test'))->toMatchArray([
                'route_exists' => true,
                'route_hash' => str_repeat('a', 64),
                'cert_path' => '/etc/orbit/certs/vite.docs.test.crt',
                'key_path' => '/etc/orbit/certs/vite.docs.test.key',
                'cert_exists' => true,
                'key_exists' => true,
            ])
            ->and($snapshot->get('api.docs.test'))->toMatchArray([
                'route_exists' => true,
                'route_hash' => str_repeat('b', 64),
                'cert_path' => '/etc/orbit/certs/api.docs.test.crt',
                'key_path' => '/etc/orbit/certs/api.docs.test.key',
                'cert_exists' => true,
                'key_exists' => true,
            ]);
    });

    it('returns empty snapshot when no caddy sites exist', function (): void {
        $node = createTestAppHostNode();
        $shell = new ProxyProbeRecordingRemoteShell('');

        $snapshot = (new ProxyRouteProbe($shell))->introspectNode($node);

        expect($snapshot->isEmpty())->toBeTrue();
    });

    it('ignores malformed lines in node scan output', function (): void {
        $node = createTestAppHostNode();
        $shell = new ProxyProbeRecordingRemoteShell(
            "vite.docs.test\t".str_repeat('a', 64)."\t/etc/orbit/certs/vite.docs.test.crt\t/etc/orbit/certs/vite.docs.test.key\t1\t1\n"
            ."malformed-line-without-tabs\n"
            ."\n",
        );

        $snapshot = (new ProxyRouteProbe($shell))->introspectNode($node);

        expect($snapshot->keys())->toHaveCount(1)
            ->and($snapshot->get('vite.docs.test'))->not->toBeNull();
    });
});

describe('proxy node-level diff', function (): void {
    it('reports route_missing for db routes not on node', function (): void {
        $node = createTestAppHostNode();
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'vite.docs.test',
            'source_hash' => str_repeat('a', 64),
        ]);

        $drift = (new ProxyRouteProbe)->diffNode($node, new ProbeSnapshot([]));

        expect(proxyProbeIssue($drift, 'proxy.route_missing')?->kind)->toBe(DriftKind::Missing);
    });

    it('reports route_extra for node routes not in db', function (): void {
        $node = createTestAppHostNode();
        $snapshot = new ProbeSnapshot([
            'extra.test' => [
                'route_exists' => true,
                'route_hash' => str_repeat('c', 64),
                'cert_path' => '/etc/orbit/certs/extra.test.crt',
                'key_path' => '/etc/orbit/certs/extra.test.key',
                'cert_exists' => true,
                'key_exists' => true,
            ],
        ]);

        $drift = (new ProxyRouteProbe)->diffNode($node, $snapshot);

        expect(proxyProbeIssue($drift, 'extra.test')?->kind)->toBe(DriftKind::Extra);
    });

    it('reports both missing and extra routes', function (): void {
        $node = createTestAppHostNode();
        ProxyRoute::factory()->create([
            'node_id' => $node->id,
            'domain' => 'db-only.test',
            'source_hash' => str_repeat('a', 64),
        ]);
        $snapshot = new ProbeSnapshot([
            'node-only.test' => [
                'route_exists' => true,
                'route_hash' => str_repeat('b', 64),
                'cert_path' => '',
                'key_path' => '',
                'cert_exists' => false,
                'key_exists' => false,
            ],
        ]);

        $drift = (new ProxyRouteProbe)->diffNode($node, $snapshot);

        expect(count($drift))->toBe(2)
            ->and(proxyProbeIssue($drift, 'proxy.route_missing')?->kind)->toBe(DriftKind::Missing)
            ->and(proxyProbeIssue($drift, 'node-only.test')?->kind)->toBe(DriftKind::Extra);
    });
});

describe('proxy adoption snapshot', function (): void {
    it('returns vhost bodies for adoption', function (): void {
        $node = createTestAppHostNode();
        $body = "vite.docs.test {\n    reverse_proxy localhost:8080\n}\n";
        $bodyB64 = base64_encode($body);
        $shell = new ProxyProbeRecordingRemoteShell(
            "vite.docs.test\t".str_repeat('a', 64)."\t{$bodyB64}\n",
        );

        $snapshot = (new ProxyRouteProbe($shell))->snapshotForAdopt($node);

        expect($snapshot->keys())->toHaveCount(1)
            ->and($snapshot->get('vite.docs.test'))->toMatchArray([
                'hash' => str_repeat('a', 64),
                'body' => $body,
            ]);
    });
});

final class ProxyProbeRecordingRemoteShell implements RemoteShell
{
    /** @var list<Node> */
    public array $nodes = [];

    /** @var list<array<string, mixed>> */
    public array $options = [];

    public function __construct(
        private readonly string $stdout,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->nodes[] = $node;
        $this->options[] = $options;

        return new RemoteShellResult(exitCode: 0, stdout: $this->stdout, stderr: '', durationMs: 1);
    }
}

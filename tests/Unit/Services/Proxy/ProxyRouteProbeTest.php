<?php

declare(strict_types=1);

use App\Data\Doctor\ProbeSnapshot;
use App\Enums\DriftKind;
use App\Models\App;
use App\Models\Node;
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
        $node = Node::factory()->create(['role' => 'app', 'status' => 'active']);
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
        $node = Node::factory()->create(['role' => 'app', 'status' => 'active']);
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
        $node = Node::factory()->create(['role' => 'app', 'status' => 'active']);
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
        $node = Node::factory()->create(['role' => 'app', 'status' => 'active']);
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
        $node = Node::factory()->create(['role' => 'app', 'status' => 'active']);
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
        $node = Node::factory()->create(['role' => 'app', 'status' => 'active']);
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

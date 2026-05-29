<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Models\ProxyRoute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const S3_PUBLISH_CALLER_WG_IP = '10.6.0.91';

/**
 * @param  array<string, mixed>  $overrides
 */
function s3PublishCallerNode(array $overrides = [], string $role = 'gateway'): Node
{
    $node = Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => S3_PUBLISH_CALLER_WG_IP,
        'wireguard_address' => S3_PUBLISH_CALLER_WG_IP,
        'status' => 'active',
    ], $overrides));

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
    ]);

    return $node;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function s3PublishStorageNode(array $overrides = []): Node
{
    $node = Node::factory()->create(array_merge([
        'name' => 'storage-1',
        'host' => '10.6.0.44',
        'wireguard_address' => '10.6.0.44',
        'status' => 'active',
    ], $overrides));

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 's3',
        'status' => 'active',
    ]);

    return $node;
}

function s3PublishRouterNode(): Node
{
    $node = Node::factory()->create([
        'name' => 'router-1',
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'router',
        'status' => 'active',
    ]);

    return $node;
}

function s3PublishIngressNode(): Node
{
    $node = Node::factory()->create([
        'name' => 'edge-1',
        'host' => '10.6.0.10',
        'wireguard_address' => '10.6.0.10',
        'status' => 'active',
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'ingress',
        'status' => 'active',
    ]);

    return $node;
}

function s3PublishRustfsTool(Node $storage, array $config = []): NodeTool
{
    return NodeTool::factory()->create([
        'node_id' => $storage->id,
        'name' => 'rustfs',
        'expected_state' => 'running',
        'config' => array_merge([
            'backend_host' => 'storage-1.s3.orbit',
            'public_hosts' => [],
        ], $config),
    ]);
}

function s3PublishPost(object $test, array $payload = []): TestResponse
{
    return $test->withServerVariables(['REMOTE_ADDR' => S3_PUBLISH_CALLER_WG_IP])
        ->postJson('/api/s3/public-hosts', $payload);
}

// ---------------------------------------------------------------------------
// Authorization
// ---------------------------------------------------------------------------

describe('S3Publish authorization', function (): void {
    it('rejects unauthenticated callers', function (): void {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.99.99'])
            ->postJson('/api/s3/public-hosts', [
                'host' => 's3.example.com',
                'node' => 'storage-1',
            ]);

        $response->assertStatus(403)
            ->assertJsonPath('error.code', 'authorization_failed');
    });
});

// ---------------------------------------------------------------------------
// Input validation
// ---------------------------------------------------------------------------

describe('S3Publish input validation', function (): void {
    it('fails when host is missing', function (): void {
        s3PublishCallerNode();

        $response = s3PublishPost($this, ['node' => 'storage-1']);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'host');
    });

    it('fails when node is missing and no s3 nodes exist', function (): void {
        s3PublishCallerNode();

        $response = s3PublishPost($this, ['host' => 's3.example.com']);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'node')
            ->assertJsonPath('error.meta.required_role', 's3');
    });

    it('auto-resolves node when exactly one active s3 node exists', function (): void {
        s3PublishCallerNode(role: 'gateway');
        $storage = s3PublishStorageNode();
        s3PublishRustfsTool($storage);
        s3PublishRouterNode();
        s3PublishIngressNode();

        $response = s3PublishPost($this, ['host' => 's3.example.com']);

        // No node specified but auto-resolved from the single visible s3 node.
        $response->assertOk()
            ->assertJsonPath('success.meta.host', 's3.example.com');
    });

    it('fails when node is missing and multiple s3 nodes exist', function (): void {
        s3PublishCallerNode(role: 'gateway');
        s3PublishStorageNode(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
        s3PublishStorageNode(['name' => 'storage-2', 'wireguard_address' => '10.6.0.45']);

        $response = s3PublishPost($this, ['host' => 's3.example.com']);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'node');
    });
});

// ---------------------------------------------------------------------------
// Prerequisites
// ---------------------------------------------------------------------------

describe('S3Publish prerequisites', function (): void {
    it('fails when the selected node is not an active s3 node', function (): void {
        s3PublishCallerNode(role: 'gateway');

        // No s3 role assignment — only gateway.
        $response = s3PublishPost($this, ['host' => 's3.example.com', 'node' => 'storage-1']);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'node')
            ->assertJsonPath('error.meta.required_role', 's3');
    });

    it('fails when no active router exists', function (): void {
        s3PublishCallerNode(role: 'gateway');
        $storage = s3PublishStorageNode();
        s3PublishRustfsTool($storage);
        // No router.

        $response = s3PublishPost($this, ['host' => 's3.example.com', 'node' => 'storage-1']);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'router')
            ->assertJsonPath('error.meta.required_role', 'router');
    });

    it('fails when no active ingress exists', function (): void {
        s3PublishCallerNode(role: 'gateway');
        $storage = s3PublishStorageNode();
        s3PublishRustfsTool($storage);
        s3PublishRouterNode();
        // No ingress.

        $response = s3PublishPost($this, ['host' => 's3.example.com', 'node' => 'storage-1']);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'ingress')
            ->assertJsonPath('error.meta.required_role', 'ingress');
    });

    it('fails when the s3 node has no rustfs tool row', function (): void {
        s3PublishCallerNode(role: 'gateway');
        s3PublishStorageNode();
        s3PublishRouterNode();
        s3PublishIngressNode();
        // No rustfs tool row.

        $response = s3PublishPost($this, ['host' => 's3.example.com', 'node' => 'storage-1']);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'node');
    });
});

// ---------------------------------------------------------------------------
// Domain conflict
// ---------------------------------------------------------------------------

describe('S3Publish domain conflict', function (): void {
    it('rejects a host owned by a non-S3 proxy route', function (): void {
        s3PublishCallerNode(role: 'gateway');
        $storage = s3PublishStorageNode();
        s3PublishRustfsTool($storage);
        s3PublishRouterNode();
        $ingress = s3PublishIngressNode();

        ProxyRoute::factory()->create([
            'domain' => 's3.example.com',
            'node_id' => $ingress->id,
            'owner_type' => 'app',
            'kind' => 'app',
            'config' => ['target' => ['type' => 'upstream', 'value' => 'http://app.test']],
        ]);

        $response = s3PublishPost($this, ['host' => 's3.example.com', 'node' => 'storage-1']);

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'proxy.domain_conflict')
            ->assertJsonPath('error.meta.owner_type', 'app');
    });

    it('does not conflict with an existing S3-owned route at the same domain', function (): void {
        s3PublishCallerNode(role: 'gateway');
        $storage = s3PublishStorageNode();
        s3PublishRustfsTool($storage, ['public_hosts' => ['s3.example.com']]);
        s3PublishRouterNode();
        $ingress = s3PublishIngressNode();

        ProxyRoute::factory()->create([
            'domain' => 's3.example.com',
            'node_id' => $ingress->id,
            'owner_type' => 'tool',
            'kind' => 'proxy',
            'config' => [
                'owner_name' => 'rustfs',
                'protocol' => 's3',
                'target' => ['type' => 'upstream', 'value' => 'https://s3.orbit'],
            ],
        ]);

        $response = s3PublishPost($this, ['host' => 's3.example.com', 'node' => 'storage-1']);

        $response->assertOk()
            ->assertJsonPath('success.meta.already_published', true);
    });

    it('accepts re-publishing an already-S3-owned route idempotently', function (): void {
        s3PublishCallerNode(role: 'gateway');
        $storage = s3PublishStorageNode();
        s3PublishRustfsTool($storage, ['public_hosts' => ['s3.example.com']]);
        s3PublishRouterNode();
        s3PublishIngressNode();

        $response = s3PublishPost($this, ['host' => 's3.example.com', 'node' => 'storage-1']);

        $response->assertOk()
            ->assertJsonPath('success.meta.host', 's3.example.com')
            ->assertJsonPath('success.meta.already_published', true)
            ->assertJsonPath('success.meta.action', 'published');
    });
});

// ---------------------------------------------------------------------------
// Success
// ---------------------------------------------------------------------------

describe('S3Publish success', function (): void {
    it('publishes the host and returns the expected success shape', function (): void {
        s3PublishCallerNode(role: 'gateway');
        $storage = s3PublishStorageNode();
        s3PublishRustfsTool($storage);
        s3PublishRouterNode();
        s3PublishIngressNode();

        $response = s3PublishPost($this, ['host' => 's3.example.com', 'node' => 'storage-1']);

        $response->assertOk()
            ->assertJsonPath('success.data.s3.node', 'storage-1')
            ->assertJsonPath('success.data.s3.private_endpoint', 'https://s3.orbit')
            ->assertJsonPath('success.meta.host', 's3.example.com')
            ->assertJsonPath('success.meta.action', 'published')
            ->assertJsonPath('success.meta.already_published', false);

        expect($response->json('success.data.s3.public_endpoints'))->toContain('https://s3.example.com');
        expect($response->json('success.data.s3.credentials_ref.tool'))->toBe('rustfs');
        expect($response->json('success.data.s3.credentials_ref.node'))->toBe('storage-1');
    });

    it('records the public host on the rustfs tool row', function (): void {
        s3PublishCallerNode(role: 'gateway');
        $storage = s3PublishStorageNode();
        $tool = s3PublishRustfsTool($storage);
        s3PublishRouterNode();
        s3PublishIngressNode();

        s3PublishPost($this, ['host' => 's3.example.com', 'node' => 'storage-1'])->assertOk();

        $tool->refresh();
        expect($tool->config['public_hosts'])->toContain('s3.example.com');
    });

    it('creates the ingress proxy route on the ingress node', function (): void {
        s3PublishCallerNode(role: 'gateway');
        $storage = s3PublishStorageNode();
        s3PublishRustfsTool($storage);
        s3PublishRouterNode();
        s3PublishIngressNode();

        s3PublishPost($this, ['host' => 's3.example.com', 'node' => 'storage-1'])->assertOk();

        $route = ProxyRoute::query()->where('domain', 's3.example.com')->firstOrFail();
        expect($route->owner_type)->toBe('tool')
            ->and($route->config['owner_name'])->toBe('rustfs')
            ->and($route->config['protocol'])->toBe('s3');
    });

    it('creates the router-owned s3.orbit service route', function (): void {
        s3PublishCallerNode(role: 'gateway');
        $storage = s3PublishStorageNode();
        s3PublishRustfsTool($storage);
        s3PublishRouterNode();
        s3PublishIngressNode();

        s3PublishPost($this, ['host' => 's3.example.com', 'node' => 'storage-1'])->assertOk();

        expect(ProxyRoute::query()->where('domain', 's3.orbit')->exists())->toBeTrue();
    });

    it('does not add a duplicate host when re-publishing an already-published host', function (): void {
        s3PublishCallerNode(role: 'gateway');
        $storage = s3PublishStorageNode();
        $tool = s3PublishRustfsTool($storage, ['public_hosts' => ['s3.example.com']]);
        s3PublishRouterNode();
        s3PublishIngressNode();

        s3PublishPost($this, ['host' => 's3.example.com', 'node' => 'storage-1'])->assertOk();

        $tool->refresh();
        $hosts = array_values($tool->config['public_hosts']);
        expect(count(array_filter($hosts, fn ($h) => $h === 's3.example.com')))->toBe(1);
    });

    it('ingress route must not target the s3 storage node directly', function (): void {
        s3PublishCallerNode(role: 'gateway');
        $storage = s3PublishStorageNode();
        s3PublishRustfsTool($storage);
        s3PublishRouterNode();
        s3PublishIngressNode();

        s3PublishPost($this, ['host' => 's3.example.com', 'node' => 'storage-1'])->assertOk();

        $route = ProxyRoute::query()->where('domain', 's3.example.com')->firstOrFail();
        $targetValue = $route->config['target']['value'];

        expect($targetValue)->toBe('https://s3.orbit')
            ->and($targetValue)->not->toContain('10.6.0.44')
            ->and($targetValue)->not->toContain('storage-1.s3.orbit');
    });
});

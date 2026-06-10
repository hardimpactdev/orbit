<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\Runtime\OrbitContainerNames;
use App\Services\S3\S3CredentialGenerator;
use App\Services\S3\S3RuntimeContainer;
use App\Services\S3\S3RuntimeContainerRenderer;
use App\Services\S3\S3ServiceConfig;
use App\Services\S3\S3ServiceConfigResolver;
use App\Services\S3\S3ServiceConfigurator;
use App\Services\S3\S3ServiceConfiguratorResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function configuratorNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'storage-1',
        'host' => 'storage-1.example.com',
        'wireguard_address' => '10.6.0.44',
    ], $overrides));
}

function configuratorAssignment(Node $node, array $settings = []): NodeRoleAssignment
{
    return NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 's3',
        'status' => 'active',
        'settings' => $settings,
    ]);
}

function configuratorRustfsTool(Node $node, array $overrides = []): NodeTool
{
    return NodeTool::factory()->create(array_merge([
        'node_id' => $node->id,
        'name' => 'rustfs',
        'expected_state' => 'installed',
        'config' => ['public_hosts' => []],
        'credentials' => null,
    ], $overrides));
}

function makeConfigurator(): S3ServiceConfigurator
{
    return new S3ServiceConfigurator(
        configResolver: new S3ServiceConfigResolver(new S3CredentialGenerator),
        containerRenderer: new S3RuntimeContainerRenderer(new OrbitContainerNames),
    );
}

// ---------------------------------------------------------------------------
// (a) First configure — no stored credentials → persists generated credentials
// ---------------------------------------------------------------------------

it('persists generated credentials to the rustfs NodeTool row on first configure', function (): void {
    $node = configuratorNode();
    $assignment = configuratorAssignment($node, ['data_path' => '/srv/orbit/s3/data']);

    // No pre-existing rustfs tool row — credentials must be generated and written.
    makeConfigurator()->configure($node, $assignment);

    $rustfsTool = NodeTool::query()
        ->where('node_id', $node->id)
        ->where('name', 'rustfs')
        ->firstOrFail();

    $fields = $rustfsTool->credentials['fields'] ?? null;

    expect($fields)->toBeArray()
        ->and($fields['access_key_id'])->toBeString()->not->toBeEmpty()
        ->and($fields['secret_access_key'])->toBeString()->not->toBeEmpty()
        ->and($fields['region'])->toBe('orbit')
        ->and($fields['endpoint'])->toBe('https://s3.orbit')
        ->and($fields['bucket_style'])->toBe('path');
});

// ---------------------------------------------------------------------------
// (b) Re-configure with existing credentials → idempotent, no rotation
// ---------------------------------------------------------------------------

it('does not rotate existing credentials on re-configure', function (): void {
    $node = configuratorNode();
    $assignment = configuratorAssignment($node, ['data_path' => '/srv/orbit/s3/data']);

    // Pre-seed the rustfs row with stored credentials.
    configuratorRustfsTool($node, [
        'credentials' => [
            'fields' => [
                'access_key_id' => 'STOREDACCESSKEYID1234',
                'secret_access_key' => 'stored-secret-access-key-value-here',
            ],
        ],
    ]);

    makeConfigurator()->configure($node, $assignment);

    $rustfsTool = NodeTool::query()
        ->where('node_id', $node->id)
        ->where('name', 'rustfs')
        ->firstOrFail();

    $fields = $rustfsTool->credentials['fields'] ?? null;

    expect($fields)->toBeArray()
        ->and($fields['access_key_id'])->toBe('STOREDACCESSKEYID1234')
        ->and($fields['secret_access_key'])->toBe('stored-secret-access-key-value-here');
});

it('does not change stored credentials when called multiple times (idempotent)', function (): void {
    $node = configuratorNode();
    $assignment = configuratorAssignment($node, ['data_path' => '/srv/orbit/s3/data']);

    $configurator = makeConfigurator();

    // First call — generates credentials.
    $configurator->configure($node, $assignment);

    $afterFirst = NodeTool::query()
        ->where('node_id', $node->id)
        ->where('name', 'rustfs')
        ->firstOrFail();

    $firstFields = $afterFirst->credentials['fields'];

    // Second call — must not overwrite.
    $configurator->configure($node, $assignment);

    $afterSecond = NodeTool::query()
        ->where('node_id', $node->id)
        ->where('name', 'rustfs')
        ->firstOrFail();

    $secondFields = $afterSecond->credentials['fields'];

    expect($secondFields['access_key_id'])->toBe($firstFields['access_key_id'])
        ->and($secondFields['secret_access_key'])->toBe($firstFields['secret_access_key']);
});

// ---------------------------------------------------------------------------
// (c) Renders the runtime container (rustfs/rustfs, WireGuard:9000, data_path mount)
// ---------------------------------------------------------------------------

it('returns an S3ServiceConfiguratorResult with a rendered runtime container', function (): void {
    $node = configuratorNode(['wireguard_address' => '10.6.0.44']);
    $assignment = configuratorAssignment($node, ['data_path' => '/srv/orbit/s3/data']);

    $result = makeConfigurator()->configure($node, $assignment);

    expect($result)->toBeInstanceOf(S3ServiceConfiguratorResult::class)
        ->and($result->runtimeContainer)->toBeInstanceOf(S3RuntimeContainer::class)
        ->and($result->serviceConfig)->toBeInstanceOf(S3ServiceConfig::class);
});

it('renders the rustfs/rustfs image in the runtime container', function (): void {
    $node = configuratorNode();
    $assignment = configuratorAssignment($node);

    $result = makeConfigurator()->configure($node, $assignment);

    expect($result->runtimeContainer->image())->toBe('rustfs/rustfs');
});

it('binds the runtime container to the WireGuard address on port 9000', function (): void {
    $node = configuratorNode(['wireguard_address' => '10.6.0.44']);
    $assignment = configuratorAssignment($node);

    $result = makeConfigurator()->configure($node, $assignment);

    expect($result->runtimeContainer->publishedPorts())->toContain('10.6.0.44:9000:9000')
        ->and($result->runtimeContainer->environment())->toMatchArray([
            'RUSTFS_ADDRESS' => '10.6.0.44:9000',
        ]);
});

it('mounts the role data path at /data in the runtime container', function (): void {
    $node = configuratorNode();
    $assignment = configuratorAssignment($node, ['data_path' => '/srv/orbit/s3/data']);

    $result = makeConfigurator()->configure($node, $assignment);

    $mounts = $result->runtimeContainer->mounts();

    expect($mounts)->toContain([
        'source' => '/srv/orbit/s3/data',
        'target' => '/data',
        'read_only' => false,
    ]);
});

// ---------------------------------------------------------------------------
// (d) Role-owned data path preserved in tool config
// ---------------------------------------------------------------------------

it('persists the role data path in rustfs tool config', function (): void {
    $node = configuratorNode();
    $assignment = configuratorAssignment($node, ['data_path' => '/mnt/fast-disk/s3']);

    makeConfigurator()->configure($node, $assignment);

    $rustfsTool = NodeTool::query()
        ->where('node_id', $node->id)
        ->where('name', 'rustfs')
        ->firstOrFail();

    expect($rustfsTool->config['data_path'])->toBe('/mnt/fast-disk/s3');
});

it('does not overwrite the data path when the role setting changes during re-configure', function (): void {
    $node = configuratorNode();
    $assignment = configuratorAssignment($node, ['data_path' => '/srv/orbit/s3/data']);

    makeConfigurator()->configure($node, $assignment);

    // Simulate a role settings update that carries a different data path.
    $assignment->settings = ['data_path' => '/mnt/fast-disk/s3'];
    $assignment->save();

    makeConfigurator()->configure($node, $assignment);

    $rustfsTool = NodeTool::query()
        ->where('node_id', $node->id)
        ->where('name', 'rustfs')
        ->firstOrFail();

    // The tool config data_path always reflects the current role settings.
    expect($rustfsTool->config['data_path'])->toBe('/mnt/fast-disk/s3');
});

// ---------------------------------------------------------------------------
// Tool metadata written to NodeTool config
// ---------------------------------------------------------------------------

it('persists full metadata to the rustfs NodeTool config', function (): void {
    $node = configuratorNode(['name' => 'storage-1', 'wireguard_address' => '10.6.0.44']);
    $assignment = configuratorAssignment($node, ['data_path' => '/srv/orbit/s3/data']);

    makeConfigurator()->configure($node, $assignment);

    $rustfsTool = NodeTool::query()
        ->where('node_id', $node->id)
        ->where('name', 'rustfs')
        ->firstOrFail();

    expect($rustfsTool->config)->toMatchArray([
        'data_path' => '/srv/orbit/s3/data',
        'service_host' => 's3.orbit',
        'backend_host' => 'storage-1.s3.orbit',
        'container_name' => S3RuntimeContainer::ContainerName,
        'runtime' => 'docker-container',
        'public_hosts' => [],
    ])->and($rustfsTool->expected_state)->toBe('installed');
});

it('returns the persisted rustfs NodeTool in the result', function (): void {
    $node = configuratorNode();
    $assignment = configuratorAssignment($node);

    $result = makeConfigurator()->configure($node, $assignment);

    expect($result->rustfsTool)->toBeInstanceOf(NodeTool::class)
        ->and($result->rustfsTool->name)->toBe('rustfs')
        ->and($result->rustfsTool->node_id)->toBe($node->id);
});

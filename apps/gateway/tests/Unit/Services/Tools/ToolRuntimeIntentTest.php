<?php

declare(strict_types=1);

use App\Enums\Processes\ProcessRuntime;
use App\Enums\ProcessRestartPolicy;
use App\Models\Node;
use App\Models\NodeTool;
use App\Models\Process;
use App\Services\Tools\ToolCatalog;
use App\Services\Tools\ToolInstanceSelector;
use App\Services\Tools\ToolRegistryFailure;
use App\Services\Tools\ToolRuntimeIntent;
use App\Services\Tools\ToolRuntimeIntentPlanner;
use App\Services\Tools\ToolRuntimeSelection;
use App\Services\Tools\ToolVersionRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('renders a managed service runtime intent as process-owned lifecycle configuration', function (): void {
    $node = managedToolRuntimeNode();

    $intent = plannedToolRuntimeIntent($node, tool: 'mysql', version: '8', runtime: 'docker-swarm');

    expect($intent)->toBeInstanceOf(ToolRuntimeIntent::class)
        ->and($intent->tool)->toBe('mysql')
        ->and($intent->instanceKey)->toBe('mysql:8')
        ->and($intent->versionFamily)->toBe('8')
        ->and($intent->expectedVersion)->toBe('8.4')
        ->and($intent->runtime)->toBe('docker-swarm')
        ->and($intent->implementationKey)->toBe('docker-swarm/ubuntu')
        ->and($intent->processName)->toBe('mysql8')
        ->and($intent->processRuntime)->toBe('docker-swarm')
        ->and($intent->serviceName)->toBe('orbit-mysql-8')
        ->and($intent->image)->toBe('mysql:8.4')
        ->and($intent->endpoint)->toBe([
            'name' => 'mysql-8',
            'kind' => 'tcp',
            'host' => '10.6.0.12',
            'port' => 3308,
        ])
        ->and($intent->ports)->toBe([
            [
                'host' => '10.6.0.12',
                'published' => 3308,
                'target' => 3306,
                'protocol' => 'tcp',
            ],
        ])
        ->and($intent->volumes)->toBe([
            [
                'name' => 'orbit-mysql-8',
                'target' => '/var/lib/mysql',
            ],
        ])
        ->and($intent->healthcheck)->toMatchArray([
            'command' => 'mysqladmin ping',
        ])
        ->and($intent->updateStrategy)->toBe([
            'order' => 'stop-first',
            'parallelism' => 1,
        ])
        ->and($intent->labels())->toMatchArray([
            'orbit.managed' => 'true',
            'orbit.tool' => 'mysql',
            'orbit.tool_instance' => 'mysql:8',
            'orbit.runtime' => 'docker-swarm',
        ])
        ->and($intent->labels()['orbit.tool.spec_hash'] ?? null)->toBe($intent->specHash());

    expect($intent->processAttributes($node, sortOrder: 4))->toMatchArray([
        'node_id' => $node->id,
        'owner_type' => $node->getMorphClass(),
        'owner_id' => $node->id,
        'name' => 'mysql8',
        'command' => 'mysqld',
        'restart_policy' => ProcessRestartPolicy::OnFailure,
        'runtime' => 'docker-swarm',
        'tool' => 'mysql',
        'sort_order' => 4,
        'runtime_config' => [
            'implementation_key' => 'docker-swarm/ubuntu',
            'service_name' => 'orbit-mysql-8',
            'image' => 'mysql:8.4',
            'endpoint' => [
                'name' => 'mysql-8',
                'kind' => 'tcp',
                'host' => '10.6.0.12',
                'port' => 3308,
            ],
        ],
    ]);
});

it('uses distinct names, ports, volumes, endpoints, labels, and hashes per version family', function (): void {
    $node = managedToolRuntimeNode();

    $mysql8 = plannedToolRuntimeIntent($node, tool: 'mysql', version: '8', runtime: 'docker');
    $mysql9 = plannedToolRuntimeIntent($node, tool: 'mysql', version: '9', runtime: 'docker');
    $redis7 = plannedToolRuntimeIntent($node, tool: 'redis', version: '7', runtime: 'docker');

    expect($mysql8)->toBeInstanceOf(ToolRuntimeIntent::class)
        ->and($mysql9)->toBeInstanceOf(ToolRuntimeIntent::class)
        ->and($redis7)->toBeInstanceOf(ToolRuntimeIntent::class);

    $intents = [$mysql8, $mysql9, $redis7];

    expect(array_map(fn (ToolRuntimeIntent $intent): string => $intent->processName, $intents))
        ->toBe(['mysql8', 'mysql9', 'redis7'])
        ->and(array_map(fn (ToolRuntimeIntent $intent): string => $intent->serviceName, $intents))
        ->toBe(['orbit-mysql-8', 'orbit-mysql-9', 'orbit-redis-7'])
        ->and(array_map(fn (ToolRuntimeIntent $intent): int => $intent->endpoint['port'], $intents))
        ->toBe([3308, 3309, 6379])
        ->and(array_map(fn (ToolRuntimeIntent $intent): string => $intent->volumes[0]['name'], $intents))
        ->toBe(['orbit-mysql-8', 'orbit-mysql-9', 'orbit-redis-7'])
        ->and(array_map(fn (ToolRuntimeIntent $intent): string => $intent->endpoint['name'], $intents))
        ->toBe(['mysql-8', 'mysql-9', 'redis-7'])
        ->and(array_unique(array_map(fn (ToolRuntimeIntent $intent): string => $intent->specHash(), $intents)))
        ->toHaveCount(3)
        ->and($mysql8->labels()['orbit.tool_instance'] ?? null)->toBe('mysql:8')
        ->and($mysql9->labels()['orbit.tool_instance'] ?? null)->toBe('mysql:9')
        ->and($redis7->labels()['orbit.tool_instance'] ?? null)->toBe('redis:7');
});

it('produces stable spec hashes for identical runtime intent inputs', function (): void {
    $node = managedToolRuntimeNode();

    $first = plannedToolRuntimeIntent($node, tool: 'mysql', version: '8', runtime: 'docker-swarm');
    $second = plannedToolRuntimeIntent($node, tool: 'mysql', version: '8', runtime: 'docker-swarm');
    $differentRuntime = plannedToolRuntimeIntent($node, tool: 'mysql', version: '8', runtime: 'docker');

    expect($first)->toBeInstanceOf(ToolRuntimeIntent::class)
        ->and($second)->toBeInstanceOf(ToolRuntimeIntent::class)
        ->and($differentRuntime)->toBeInstanceOf(ToolRuntimeIntent::class)
        ->and($first->specHash())->toBe($second->specHash())
        ->and($first->specHash())->not->toBe($differentRuntime->specHash());
});

it('fails before rendering when a tool instance or related process already exists', function (): void {
    $node = managedToolRuntimeNode();

    NodeTool::factory()->for($node, 'node')->create([
        'name' => 'mysql',
        'instance_key' => 'mysql:8',
        'version_family' => '8',
        'runtime' => 'docker',
        'expected_state' => 'running',
    ]);

    $duplicateTool = plannedToolRuntimeIntent($node, tool: 'mysql', version: '8', runtime: 'docker');

    expect($duplicateTool)->toBeInstanceOf(ToolRegistryFailure::class)
        ->and($duplicateTool->code)->toBe('tool.instance_exists')
        ->and($duplicateTool->meta)->toMatchArray([
            'node' => 'data-1',
            'tool' => 'mysql',
            'instance' => 'mysql:8',
            'source' => 'node_tools',
        ]);

    NodeTool::query()->delete();

    Process::factory()->forOwner($node)->create([
        'name' => 'mysql8',
        'command' => 'mysqld',
        'runtime' => ProcessRuntime::Docker,
        'tool' => 'mysql',
    ]);

    $duplicateProcess = plannedToolRuntimeIntent($node, tool: 'mysql', version: '8', runtime: 'docker');

    expect($duplicateProcess)->toBeInstanceOf(ToolRegistryFailure::class)
        ->and($duplicateProcess->code)->toBe('tool.instance_exists')
        ->and($duplicateProcess->meta)->toMatchArray([
            'node' => 'data-1',
            'tool' => 'mysql',
            'instance' => 'mysql:8',
            'process' => 'mysql8',
            'source' => 'processes',
        ]);
});

it('fails before rendering when a service endpoint would collide with existing tool intent', function (): void {
    $node = managedToolRuntimeNode();

    NodeTool::factory()->for($node, 'node')->create([
        'name' => 'redis',
        'instance_key' => 'redis:7',
        'version_family' => '7',
        'runtime' => 'docker',
        'expected_state' => 'running',
        'config' => [
            'endpoints' => [
                [
                    'name' => 'redis-7',
                    'kind' => 'tcp',
                    'host' => '10.6.0.12',
                    'port' => 3308,
                ],
            ],
        ],
    ]);

    $conflict = plannedToolRuntimeIntent($node, tool: 'mysql', version: '8', runtime: 'docker');

    expect($conflict)->toBeInstanceOf(ToolRegistryFailure::class)
        ->and($conflict->code)->toBe('tool.endpoint_conflict')
        ->and($conflict->meta)->toMatchArray([
            'node' => 'data-1',
            'tool' => 'mysql',
            'instance' => 'mysql:8',
            'host' => '10.6.0.12',
            'port' => 3308,
            'existing_tool' => 'redis',
            'existing_instance' => 'redis:7',
        ]);
});

it('carries unsupported runtime failures into runtime intent planning before side effects', function (): void {
    $node = managedToolRuntimeNode();
    $instance = managedToolInstanceSelection('mysql', '8');

    $unsupportedRuntime = ToolRuntimeSelection::resolve(
        catalog: app(ToolCatalog::class),
        tool: 'mysql',
        runtime: 'podman',
        platform: (string) $node->platform,
    );

    $unsupportedPlatform = ToolRuntimeSelection::resolve(
        catalog: app(ToolCatalog::class),
        tool: 'mysql',
        runtime: 'docker-swarm',
        platform: 'macos_15-4',
    );

    $planner = app(ToolRuntimeIntentPlanner::class);

    expect($planner->plan($node, $instance, $unsupportedRuntime))->toBe($unsupportedRuntime)
        ->and($unsupportedRuntime)->toBeInstanceOf(ToolRegistryFailure::class)
        ->and($unsupportedRuntime->code)->toBe('tool.runtime_unsupported')
        ->and($planner->plan($node, $instance, $unsupportedPlatform))->toBe($unsupportedPlatform)
        ->and($unsupportedPlatform)->toBeInstanceOf(ToolRegistryFailure::class)
        ->and($unsupportedPlatform->code)->toBe('tool.runtime_platform_unsupported');
});

function managedToolRuntimeNode(): Node
{
    return Node::factory()->database()->create([
        'name' => 'data-1',
        'platform' => 'ubuntu_24-04',
        'wireguard_address' => '10.6.0.12',
    ]);
}

function managedToolRuntimeSelection(string $tool, string $runtime, string $platform): ToolRuntimeSelection
{
    $selection = ToolRuntimeSelection::resolve(
        catalog: app(ToolCatalog::class),
        tool: $tool,
        runtime: $runtime,
        platform: $platform,
    );

    expect($selection)->toBeInstanceOf(ToolRuntimeSelection::class);

    return $selection;
}

function managedToolInstanceSelection(string $tool, string $version): ToolInstanceSelector
{
    $selection = ToolInstanceSelector::forInstall(
        catalog: app(ToolCatalog::class),
        tool: $tool,
        version: ToolVersionRequest::fromInput($version),
        instance: null,
    );

    expect($selection)->toBeInstanceOf(ToolInstanceSelector::class);

    return $selection;
}

function plannedToolRuntimeIntent(
    Node $node,
    string $tool,
    string $version,
    string $runtime,
): ToolRuntimeIntent|ToolRegistryFailure {
    return app(ToolRuntimeIntentPlanner::class)->plan(
        node: $node,
        instance: managedToolInstanceSelection($tool, $version),
        runtime: managedToolRuntimeSelection($tool, $runtime, (string) $node->platform),
    );
}

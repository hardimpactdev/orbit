<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Tools\ToolPayloadMapper;
use App\Services\Tools\ToolRegistry;
use App\Services\Tools\ToolRegistryFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

describe('tool command shared contract', function (): void {
    it('maps node tool models to the canonical JSON entity shape', function (): void {
        $node = new Node(['name' => 'app-contract-1']);
        $tool = new NodeTool([
            'name' => 'redis',
            'expected_state' => 'running',
            'expected_version' => '7.2',
            'config' => [
                'endpoints' => [
                    [
                        'name' => 'redis',
                        'kind' => 'tcp',
                        'host' => 'redis.app-contract-1.test',
                        'port' => 6379,
                    ],
                ],
            ],
        ]);
        $tool->setRelation('node', $node);

        $payload = app(ToolPayloadMapper::class)->toArray($tool);

        expect(array_keys($payload))->toBe([
            'name',
            'node',
            'expected_state',
            'observed_state',
            'version',
            'managed',
            'endpoints',
        ])
            ->and($payload)->toMatchArray([
                'name' => 'redis',
                'node' => 'app-contract-1',
                'expected_state' => 'running',
                'observed_state' => null,
                'version' => '7.2',
                'managed' => true,
                'endpoints' => [
                    [
                        'name' => 'redis',
                        'kind' => 'tcp',
                        'host' => 'redis.app-contract-1.test',
                        'port' => 6379,
                    ],
                ],
            ])
            ->and($payload['name'])->toBeString()
            ->and($payload['node'])->toBeString()
            ->and($payload['expected_state'])->toBeString()
            ->and($payload['observed_state'])->toBeNull()
            ->and($payload['version'])->toBeString()
            ->and($payload['managed'])->toBeBool()
            ->and($payload['endpoints'])->toBeArray();
    });

    it('keeps observed state out of the registry model because tool:list does not probe live state', function (): void {
        $tool = new NodeTool;

        expect($tool->getFillable())->toBe([
            'node_id',
            'name',
            'expected_state',
            'expected_version',
            'config',
            'credentials',
        ])
            ->and($tool->getFillable())->not->toContain('observed_state');
    });

    it('filters registry lists to visible app nodes by node selector and app selector', function (): void {
        $firstNode = Node::factory()->create(['name' => 'app-contract-a', 'role' => 'app', 'status' => 'active']);
        $secondNode = Node::factory()->create(['name' => 'app-contract-b', 'role' => 'app', 'status' => 'active']);
        $inactiveNode = Node::factory()->create(['name' => 'app-contract-c', 'role' => 'app', 'status' => 'inactive']);
        $gatewayNode = Node::factory()->create(['name' => 'gateway-contract', 'role' => 'gateway', 'status' => 'active']);

        App::factory()->create([
            'name' => 'docs-contract',
            'domain' => 'docs-contract.test',
            'node_id' => $secondNode->id,
        ]);

        NodeTool::factory()->create(['name' => 'z-redis', 'node_id' => $firstNode->id]);
        NodeTool::factory()->create(['name' => 'a-caddy', 'node_id' => $firstNode->id]);
        NodeTool::factory()->create(['name' => 'php', 'node_id' => $secondNode->id]);
        NodeTool::factory()->create(['name' => 'hidden', 'node_id' => $inactiveNode->id]);
        NodeTool::factory()->create(['name' => 'gateway-only', 'node_id' => $gatewayNode->id]);

        $registry = app(ToolRegistry::class);

        expect($registry->list()->map(fn (NodeTool $tool): string => "{$tool->node?->name}:{$tool->name}")->all())->toBe([
            'app-contract-a:a-caddy',
            'app-contract-a:z-redis',
            'app-contract-b:php',
        ])
            ->and($registry->list(node: 'app-contract-a')->pluck('name')->all())->toBe(['a-caddy', 'z-redis'])
            ->and($registry->list(app: 'docs-contract')->pluck('name')->all())->toBe(['php'])
            ->and($registry->list(app: 'docs-contract.test')->pluck('name')->all())->toBe(['php']);
    });

    it('returns contract failures for invalid or conflicting registry filters', function (): void {
        $firstNode = Node::factory()->create(['name' => 'app-contract-a', 'role' => 'app', 'status' => 'active']);
        $secondNode = Node::factory()->create(['name' => 'app-contract-b', 'role' => 'app', 'status' => 'active']);

        App::factory()->create([
            'name' => 'docs-contract',
            'node_id' => $secondNode->id,
        ]);

        $registry = app(ToolRegistry::class);

        expect($registry->validateFilters(node: $firstNode->name))->toBeNull()
            ->and($registry->validateFilters(app: 'docs-contract'))->toBeNull();

        $invalidNode = $registry->validateFilters(node: 'missing-node');
        $invalidApp = $registry->validateFilters(app: 'missing-app');
        $conflictingApp = $registry->validateFilters(node: $firstNode->name, app: 'docs-contract');

        expect($invalidNode)->toBeInstanceOf(ToolRegistryFailure::class)
            ->and($invalidNode->code)->toBe('validation_failed')
            ->and($invalidNode->meta)->toMatchArray(['field' => 'node', 'value' => 'missing-node'])
            ->and($invalidApp)->toBeInstanceOf(ToolRegistryFailure::class)
            ->and($invalidApp->code)->toBe('validation_failed')
            ->and($invalidApp->meta)->toMatchArray(['field' => 'app', 'value' => 'missing-app'])
            ->and($conflictingApp)->toBeInstanceOf(ToolRegistryFailure::class)
            ->and($conflictingApp->code)->toBe('validation_failed')
            ->and($conflictingApp->meta)->toMatchArray(['field' => 'app', 'value' => 'docs-contract']);
    });
});

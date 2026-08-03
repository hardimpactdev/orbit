<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Tools\ToolPayloadMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createAgentEndpointNode(string $name): Node
{
    $node = Node::factory()->create([
        'name' => $name,
        'status' => 'active',
        'tld' => 'agent',
        'platform' => 'ubuntu_24_04',
    ]);
    $node->roleAssignments()->create([
        'role' => 'agent',
        'status' => 'active',
        'settings' => ['tld' => 'agent'],
    ]);

    return $node;
}

it('derives hermes consumer https endpoints from catalog and node tld', function (): void {
    $node = createAgentEndpointNode('agent-1');

    $hermes = NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'hermes',
        'expected_state' => 'installed',
        'config' => [],
    ]);

    $mapper = app(ToolPayloadMapper::class);

    expect($mapper->toArray($hermes)['endpoints'])
        ->toBe([[
            'name' => 'hermes',
            'kind' => 'https',
            'url' => 'https://hermes.agent',
            'host' => 'hermes.agent',
            'port' => 443,
            'upstream_port' => 8080,
        ]]);
});

it('does not require persisted endpoint copies for agent tools', function (): void {
    $node = createAgentEndpointNode('agent-2');

    $tool = NodeTool::factory()->create([
        'node_id' => $node->id,
        'name' => 'hermes',
        'expected_state' => 'installed',
        'config' => ['endpoints' => [['name' => 'stale', 'host' => 'stale.example', 'port' => 1]]],
    ]);

    $payload = app(ToolPayloadMapper::class)->toArray($tool);

    expect($payload['endpoints'][0]['url'] ?? null)
        ->toBe('https://hermes.agent')
        ->and($payload['endpoints'][0]['host'] ?? null)
        ->not->toBe('stale.example');
});

it('does not register openclaw as a supported agent tool endpoint', function (): void {
    expect(app(\App\Services\Tools\ToolCatalog::class)->supports('openclaw'))
        ->toBeFalse();
});

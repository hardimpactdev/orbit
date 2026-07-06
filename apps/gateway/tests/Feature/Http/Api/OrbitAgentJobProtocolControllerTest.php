<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function post_retired_orbit_agent_protocol_json(Node $node, string $uri): TestResponse
{
    return test()->call('POST', $uri, [], [], [], ['REMOTE_ADDR' => $node->wireguard_address]);
}

it('does not expose the retired Orbit Agent claim endpoint', function (): void {
    $node = Node::factory()
        ->appDev(['tld' => 'agent-a.app'])
        ->orbitAgentCapable()
        ->create([
            'name' => 'agent-a',
            'host' => 'agent-a.test',
            'platform' => 'macos_15-5',
            'wireguard_address' => '10.6.0.41',
            'status' => 'active',
        ]);

    $this->postJson('/api/orbit-agent/jobs/claim')->assertNotFound();

    post_retired_orbit_agent_protocol_json($node, '/api/orbit-agent/jobs/claim')
        ->assertNotFound();
});

it('does not expose the retired Orbit Agent lifecycle event endpoint', function (): void {
    $node = Node::factory()
        ->appDev(['tld' => 'agent-a.app'])
        ->orbitAgentCapable()
        ->create([
            'name' => 'agent-a',
            'host' => 'agent-a.test',
            'platform' => 'macos_15-5',
            'wireguard_address' => '10.6.0.41',
            'status' => 'active',
        ]);

    post_retired_orbit_agent_protocol_json($node, '/api/orbit-agent/jobs/job-123/events')
        ->assertNotFound();
});

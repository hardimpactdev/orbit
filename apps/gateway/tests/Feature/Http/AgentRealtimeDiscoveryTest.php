<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WebSocket\WebSocketAppIssuer;
use App\Domain\WebSocket\WebSocketRegistry;
use App\Infrastructure\WebSocket\WebSocketDnsTarget;
use App\Models\Node;
use App\Models\WebSocketApp;
use App\Models\WebSocketRuntime;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(LazilyRefreshDatabase::class);

function agent_discovery_node(string $name, string $address): Node
{
    return Node::withoutEvents(function () use ($name, $address): Node {
        $node = Node::query()->create([
            'name' => $name, 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => $address,
            'wireguard_ip' => $address, 'user' => 'orbit',
        ]);
        $node->forceFill(['ssh_host_fingerprint' => 'SHA256:fingerprint', 'agent_secret_hash' => hash('sha256', 'agent-secret')])->save();

        return $node;
    });
}

function agent_discovery_get(Node $node): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip])
        ->withToken('agent-secret')
        ->getJson('/api/v1/agent/realtime');
}

/** The websocket role on its own Node, which serves `reverb.orbit`, the Gateway app, and a serving report made `$age` seconds ago. */
function agent_discovery_reverb(int $age): void
{
    $websocket = agent_discovery_node('services', '10.44.0.5');
    $websocket->roles()->create(['role' => RoleName::WebSocket, 'status' => 'active']);
    (new WebSocketDnsTarget)->markServing($websocket->id);
    (new WebSocketAppIssuer)->issue(WebSocketApp::GatewayName);
    WebSocketRuntime::query()->create([
        'id' => (string) Str::uuid(), 'node_id' => $websocket->id, 'slot' => 'default', 'registry_token' => Str::random(64),
        'required' => true, 'boot_id' => (string) Str::uuid(),
        'epoch' => (new WebSocketRegistry)->version()['epoch'], 'applied_revision' => 1, 'state' => 'serving',
        'reported_at' => now()->subSeconds($age),
    ]);
}

describe('GET /api/v1/agent/realtime', function (): void {
    it('answers 503 realtime.not_serving while the only serving report is older than 60 seconds, as after a cold start', function (): void {
        $agent = agent_discovery_node('app-dev', '10.44.0.2');
        agent_discovery_reverb(age: 61);

        // An error puts the agent on its 1 to 30 s back-off; null values would make it wait 60 s.
        agent_discovery_get($agent)->assertStatus(503)
            ->assertJsonPath('error.code', 'realtime.not_serving')
            ->assertJsonMissingPath('data');
    });

    it('keeps the null connection without any websocket role, so the agent asks again in 60 seconds', function (): void {
        $agent = agent_discovery_node('app-dev', '10.44.0.2');

        agent_discovery_get($agent)->assertOk()
            ->assertJsonPath('data.url', null)->assertJsonPath('data.address', null)->assertJsonPath('data.key', null)
            ->assertJsonPath('data.channel', "presence-node.{$agent->id}")
            ->assertJsonPath('data.member', "agent.{$agent->id}");
    });

    it('returns the Reverb connection once its serving report is fresh', function (): void {
        $agent = agent_discovery_node('app-dev', '10.44.0.2');
        agent_discovery_reverb(age: 1);
        $key = WebSocketApp::query()->where('name', WebSocketApp::GatewayName)->sole()->key;

        agent_discovery_get($agent)->assertOk()
            ->assertJsonPath('data.url', 'wss://reverb.orbit')
            ->assertJsonPath('data.address', '10.44.0.5')
            ->assertJsonPath('data.key', $key);
    });
});

<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\Operation;

beforeEach(function (): void {
    $this->node = Node::query()->create(['name' => 'edge', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.3']);
    $this->node->accessibleNodes()->attach($this->node);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->url = '/api/v1/nodes/'.$this->node->id.'/live-firewall-rules';
});

it('returns the recorded native comparison without recomputing against later Node intent', function (): void {
    $row = ['name' => 'native-name', 'comment' => 'owned', 'action' => 'allow', 'source' => 'any', 'destination' => 'any',
        'port' => '443', 'protocol' => 'tcp', 'interface' => null, 'family' => 'v4', 'match' => 'drift'];
    Operation::query()->create(['kind' => 'firewall.inspect', 'target_type' => 'node_firewall', 'target_id' => $this->node->id,
        'node_id' => $this->node->id, 'status' => 'succeeded', 'input' => [],
        'result' => ['backend' => 'active', 'observed_at' => '2026-11-01T05:00:00.000Z', 'live' => [],
            'comparison' => ['live' => [$row], 'missing' => [[...$row, 'match' => 'missing']]]]]);
    $this->node->update(['settings' => ['apps' => ['path' => '/new-apps-root']]]);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.live', [$row])
        ->assertJsonPath('data.missing', [[...$row, 'match' => 'missing']])
        ->assertJsonPath('data.observed_at', '2026-11-01T05:00:00.000Z');
});

it('returns unavailable for an incomplete snapshot instead of falling back to Gateway comparison', function (): void {
    Operation::query()->create(['kind' => 'firewall.inspect', 'target_type' => 'node_firewall', 'target_id' => $this->node->id,
        'node_id' => $this->node->id, 'status' => 'succeeded', 'input' => [],
        'result' => ['backend' => 'active', 'observed_at' => '2026-11-01T05:00:00.000Z', 'live' => []]]);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data', [
        'backend_status' => 'unreachable', 'observed_at' => null, 'live' => [], 'missing' => [],
    ]);
});

it('offers no route that changes a live rule', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(static fn ($route): bool => str_contains($route->uri(), 'live-firewall-rules'));
    expect($routes)->toHaveCount(1)->and($routes->first()->methods())->toBe(['GET', 'HEAD']);
});

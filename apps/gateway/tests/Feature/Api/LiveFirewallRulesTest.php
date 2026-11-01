<?php

declare(strict_types=1);

use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\Operation;

beforeEach(function (): void {
    $this->node = Node::query()->create(['name' => 'edge', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.3']);
    $this->node->accessibleNodes()->attach($this->node);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->url = '/api/v1/nodes/'.$this->node->id.'/live-firewall-rules';
});

it('marks every desired rule unverifiable without reading the Node or stored snapshots', function (): void {
    FirewallRule::query()->create(['node_id' => $this->node->id, 'name' => 'web', 'action' => 'allow', 'source' => '192.0.2.9',
        'port' => '443', 'protocol' => 'tcp', 'status' => 'active']);
    Operation::query()->create(['kind' => 'doctor.inspect', 'target_type' => 'node', 'target_id' => $this->node->id, 'node_id' => $this->node->id,
        'status' => 'succeeded', 'input' => [], 'result' => ['firewall' => [$this->node->id => ['backend' => 'active', 'live' => [], 'missing' => []]]]]);
    $response = $this->getJson($this->url)->assertOk()->assertJsonPath('data.backend_status', 'unverifiable')
        ->assertJsonPath('data.observed_at', null)->assertJsonPath('data.missing', []);
    $live = collect($response->json('data.live'));
    expect($live)->not->toBeEmpty()->and($live->pluck('match')->unique()->all())->toBe(['unverifiable'])
        ->and($live->firstWhere('name', 'web'))->toBe(['name' => 'web', 'comment' => 'orbit:node:'.$this->node->id.':firewall:web', 'action' => 'allow',
            'source' => '192.0.2.9', 'destination' => 'any', 'port' => '443', 'protocol' => 'tcp', 'interface' => null, 'family' => 'v4', 'match' => 'unverifiable']);
});

it('offers no route that changes a live rule', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(static fn ($route): bool => str_contains($route->uri(), 'live-firewall-rules'));
    expect($routes)->toHaveCount(1)->and($routes->first()->methods())->toBe(['GET', 'HEAD']);
});

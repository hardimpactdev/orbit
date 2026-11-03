<?php

declare(strict_types=1);

use App\Domain\NodeFacts\NodeFactKind;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeFact;

beforeEach(function (): void {
    $this->node = Node::query()->create(['name' => 'edge', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.3']);
    $this->node->accessibleNodes()->attach($this->node);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->url = '/api/v1/nodes/'.$this->node->id.'/live-firewall-rules';
});

function liveUfw(Node $node, array $data): void
{
    NodeFact::query()->create(['node_id' => $node->id, 'kind' => NodeFactKind::Ufw, 'subject' => '', 'digest' => hash('sha256', json_encode($data)), 'data' => $data,
        'observed_at' => '2026-11-03T02:00:00Z', 'received_at' => '2026-11-03T02:00:01Z']);
}

function ufwRule(array $rule = []): array
{
    return [...['action' => 'allow', 'protocol' => 'tcp', 'port' => '443', 'destination' => '0.0.0.0/0', 'source_port' => 'any', 'source' => '192.0.2.9',
        'direction' => 'in', 'in_interface' => null, 'out_interface' => null, 'family' => 'v4', 'comment' => 'orbit web', 'app' => false], ...$rule];
}

it('marks every desired rule unverifiable until the agent reports UFW, without reading the Node', function (): void {
    FirewallRule::query()->create(['node_id' => $this->node->id, 'label' => 'web', 'action' => 'allow', 'source' => '192.0.2.9',
        'port' => '443', 'protocol' => 'tcp', 'status' => 'active']);
    $response = $this->getJson($this->url)->assertOk()->assertJsonPath('data.backend_status', 'unverifiable')
        ->assertJsonPath('data.observed_at', null)->assertJsonPath('data.missing', []);
    $live = collect($response->json('data.live'));
    expect($live)->not->toBeEmpty()->and($live->pluck('match')->unique()->all())->toBe(['unverifiable'])
        ->and($live->firstWhere('name', '443/tcp from 192.0.2.9'))->toBe(['name' => '443/tcp from 192.0.2.9', 'comment' => 'orbit web', 'action' => 'allow',
            'source' => '192.0.2.9', 'destination' => 'any', 'port' => '443', 'protocol' => 'tcp', 'interface' => null, 'family' => 'v4', 'match' => 'unverifiable']);
});

it('compares the reported UFW rules with the intended ones', function (): void {
    FirewallRule::query()->create(['node_id' => $this->node->id, 'label' => 'web', 'action' => 'allow', 'source' => '192.0.2.9',
        'port' => '443', 'protocol' => 'tcp', 'status' => 'active']);
    liveUfw($this->node, ['installed' => true, 'enabled' => true, 'rules' => [ufwRule(), ufwRule(['port' => '8080', 'comment' => 'by hand', 'source' => '0.0.0.0/0']),
        ufwRule(['family' => 'v6', 'source' => '::/0'])]]);
    $data = $this->getJson($this->url)->assertOk()->assertJsonPath('data.backend_status', 'active')->assertJsonPath('data.observed_at', '2026-11-03T02:00:00Z')->json('data');
    expect(collect($data['live'])->map(fn (array $row): array => [$row['name'], $row['port'], $row['source'], $row['match']])->all())
        ->toBe([['443/tcp from 192.0.2.9', '443', '192.0.2.9', 'exact'], ['by hand', '8080', 'any', 'unmanaged']])
        ->and(collect($data['missing'])->pluck('name'))->not->toContain('443/tcp from 192.0.2.9')->not->toBeEmpty();
});

it('reports a disabled UFW as inactive with nothing live, and a Node without UFW as absent', function (): void {
    liveUfw($this->node, ['installed' => true, 'enabled' => false, 'rules' => [ufwRule()]]);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.backend_status', 'inactive')->assertJsonPath('data.live', [])->assertJsonPath('data.missing', []);
    NodeFact::query()->delete();
    liveUfw($this->node, ['installed' => false, 'enabled' => false, 'rules' => []]);
    $this->getJson($this->url)->assertOk()->assertJsonPath('data.backend_status', 'absent');
});

it('marks every rule unverifiable when one reported rule cannot be read', function (): void {
    liveUfw($this->node, ['installed' => true, 'enabled' => true, 'rules' => [ufwRule(), ['unreadable' => true]]]);
    $live = collect($this->getJson($this->url)->assertOk()->assertJsonPath('data.backend_status', 'unverifiable')->json('data.live'));
    expect($live->pluck('match')->unique()->all())->toBe(['unverifiable']);
});

it('offers no route that changes a live rule', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(static fn ($route): bool => str_contains($route->uri(), 'live-firewall-rules'));
    expect($routes)->toHaveCount(1)->and($routes->first()->methods())->toBe(['GET', 'HEAD']);
});

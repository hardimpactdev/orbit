<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(LazilyRefreshDatabase::class);

function http_node(string $name, string $ip): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => $ip,
        'wireguard_ip' => $ip, 'user' => 'orbit',
    ]));
}

function http_rule(Node $node, string $port): void
{
    $node->firewallRules()->create(['action' => 'allow', 'port' => $port, 'protocol' => 'tcp', 'source' => 'any', 'status' => LifecycleStatus::Active]);
}

function http_list(Node $caller, string $query = ''): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->getJson('/api/v1/firewall-rules'.$query);
}

describe('GET /api/v1/firewall-rules', function (): void {
    beforeEach(function (): void {
        $this->caller = http_node('operator', '10.44.0.9');
        $this->dev = http_node('app-dev', '10.44.0.2');
        $this->prod = http_node('app-prod', '10.44.0.3');
        $this->hidden = http_node('hidden', '10.44.0.4');
        http_rule($this->dev, '5432');
        http_rule($this->prod, '25');
        http_rule($this->hidden, '80');
        $this->caller->accessibleNodes()->attach([$this->dev->id, $this->prod->id]);
    });

    it('lists the rules of the Nodes the caller may address', function (): void {
        $response = http_list($this->caller)->assertOk();

        expect(array_column($response->json('data'), 'node'))->toBe(['app-dev', 'app-prod'])
            ->and($response->json('data.0.rules.0.port'))->toBe('5432')
            ->and($response->json('data.0'))->not->toHaveKeys(['managed', 'live']);
    });

    it('lists one Node with Orbit rules and the live comparison when asked', function (): void {
        $response = http_list($this->caller, '?node='.$this->dev->id.'&managed=1&live=1')->assertOk();

        expect($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.node'))->toBe('app-dev')
            ->and(array_column($response->json('data.0.managed'), 'name'))->toContain('orbit:wireguard-members')
            ->and($response->json('data.0.rules.0.match'))->toBe('unverifiable')
            ->and($response->json('data.0.live.backend_status'))->toBe('unverifiable');
    });

    it('refuses a --node that is not a positive integer', function (string $node): void {
        http_list($this->caller, '?node='.$node)->assertStatus(422)->assertJsonPath('error.code', 'firewall.node_id_invalid');
    })->with(['text' => 'abc', 'zero' => '0', 'empty' => '']);

    it('refuses a Node the caller has no access grant to', function (): void {
        http_list($this->caller, '?node='.$this->hidden->id)->assertStatus(403)->assertJsonPath('error.code', 'node_access.required');
    });

    it('refuses a caller with no access grant at all', function (): void {
        http_list($this->hidden)->assertStatus(403)->assertJsonPath('error.code', 'node_access.required');
    });
});

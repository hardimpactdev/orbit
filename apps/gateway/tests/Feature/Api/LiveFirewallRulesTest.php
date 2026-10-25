<?php

declare(strict_types=1);

use App\Domain\Firewall\FirewallAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeRole;
use Tests\Support\FakeAgentCommandRunner;

beforeEach(function (): void {
    $this->node = Node::query()->create([
        'name' => 'edge',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'public_ssh_port' => 22,
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $this->node->accessibleNodes()->attach($this->node);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->url = "/api/v1/nodes/{$this->node->id}/live-firewall-rules";
});

describe('firewall:live:list', function (): void {
    it('lists live UFW, paints leftover public SSH as unmanaged, and flags a missing desired rule', function (): void {
        NodeRole::query()->create([
            'node_id' => $this->node->id,
            'role' => RoleName::Gateway,
            'status' => LifecycleStatus::Active,
        ]);
        FirewallRule::query()->create([
            'node_id' => $this->node->id,
            'name' => 'office-web',
            'action' => FirewallAction::Allow,
            'source' => '10.6.0.0/24',
            'protocol' => 'tcp',
            'port' => '443',
            'status' => LifecycleStatus::Active,
        ]);
        live_firewall_agent([
            live_firewall_shape('orbit:public-ssh-recovery', '22', protocol: 'tcp'),
            live_firewall_shape('orbit:wireguard-members', destination: '10.44.0.3', interface: 'orbit'),
            live_firewall_shape("orbit:node:{$this->node->id}:firewall:office-web", '443', '10.6.0.0/24', protocol: 'tcp'),
        ]);

        $data = $this->getJson($this->url)->assertOk()->json('data');

        expect($data['backend_status'])
            ->toBe('active')
            ->and(array_map(static fn (array $row): array => [$row['name'], $row['match']], $data['live']))
            ->toBe([
                ['orbit:public-ssh-recovery', 'unmanaged'],
                ['orbit:wireguard-members', 'exact'],
                ['office-web', 'exact'],
            ])
            ->and($data['missing'])
            ->toHaveCount(1)
            ->and($data['missing'][0])
            ->toMatchArray(['name' => 'orbit:gateway-https', 'match' => 'missing']);
    });

    it('agrees with the managed catalog on exact Router DNS and HTTPS and rejects stale or different live rules', function (): void {
        [$router, $member] = router_lan_topology();
        $this->node->accessibleNodes()->attach($router);
        $lines = [live_firewall_shape('orbit:wireguard-members', destination: '10.44.0.10', interface: 'orbit')];
        $expected = [];
        foreach ([['https', 'tcp', '443'], ['dns-udp', 'udp', '53'], ['dns-tcp', 'tcp', '53']] as $index => [$family, $protocol, $port]) {
            $comment = 'orbit:router-lan-'.$family.':'.$member->id;
            $lines[] = live_firewall_shape($comment, $port, '10.20.0.11', '10.20.0.10', protocol: $protocol);
            $expected[] = [
                'name' => $comment, 'role' => 'router', 'action' => 'allow',
                'source' => '10.20.0.11', 'destination' => '10.20.0.10',
                'port' => $port, 'protocol' => $protocol, 'interface' => null,
            ];
        }
        live_firewall_agent($lines);

        $managed = $this->getJson("/api/v1/nodes/{$router->id}/managed-firewall-rules")->assertOk()->json('data');
        expect(array_slice($managed, 1))->toBe($expected);
        $url = "/api/v1/nodes/{$router->id}/live-firewall-rules";
        $data = $this->getJson($url)->assertOk()->json('data');
        expect(array_column($data['live'], 'match'))->toBe(['exact', 'exact', 'exact', 'exact']);
        expect($data['missing'])->toBe([]);

        $lines[2]['port'] = '54';
        $lines[2]['protocol'] = 'tcp';
        $lines[] = live_firewall_shape('orbit:router-lan-dns-tcp:999', '53', '10.20.0.99', '10.20.0.10', protocol: 'tcp');
        live_firewall_agent($lines);
        $data = $this->getJson($url)->assertOk()->json('data');
        expect(array_column($data['live'], 'match'))->toBe(['exact', 'exact', 'drift', 'exact', 'unmanaged']);
    });

    it('returns no live or missing rows when UFW cannot be read', function (): void {
        live_firewall_agent(throws: true);

        $this->getJson($this->url)->assertOk()->assertJsonPath('data', [
            'backend_status' => 'unreachable',
            'live' => [],
            'missing' => [],
        ]);
    });

    it('offers no route that changes a live rule', function (): void {
        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => str_contains($route->uri(), 'live-firewall-rules'));

        expect($routes)->toHaveCount(1)
            ->and($routes->first()->methods())->toBe(['GET', 'HEAD']);
    });
});

/** @param list<array<string, mixed>> $live */
function live_firewall_agent(array $live = [], bool $throws = false): void
{
    $agents = new FakeAgentCommandRunner()->answer('firewall.inspect', $throws
        ? static fn () => throw new RuntimeException('node unreachable')
        : ['backend' => 'active', 'ownerships' => [], 'matches' => true, 'live' => $live]);
    app()->instance(AgentCommandRunner::class, $agents);
}

/** @return array<string, mixed> */
function live_firewall_shape(string $comment, string $port = 'any', string $source = 'any', string $destination = 'any', ?string $interface = null, string $protocol = 'any'): array
{
    return ['comment' => $comment, 'action' => 'allow', 'direction' => 'in', 'source' => $source,
        'destination' => $destination, 'port' => $port, 'protocol' => $protocol,
        'in_interface' => $interface, 'out_interface' => null, 'family' => 'v4'];
}

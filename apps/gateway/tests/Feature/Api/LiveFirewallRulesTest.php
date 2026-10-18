<?php

declare(strict_types=1);

use App\Domain\Firewall\FirewallAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeRole;

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
        live_firewall_ssh(<<<'OUTPUT'
            Status: active

                 To                         Action      From
            [ 1] 22/tcp                     ALLOW IN    Anywhere                   # orbit:public-ssh-recovery
            [ 2] 22/tcp (v6)                ALLOW IN    Anywhere (v6)              # orbit:public-ssh-recovery
            [ 3] 10.44.0.3 on orbit         ALLOW IN    Anywhere                   # orbit:wireguard-members
            [ 4] 443/tcp                    ALLOW IN    10.6.0.0/24                # orbit:node:NODE:firewall:office-web
            OUTPUT);

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
        $lines = ['Status: active', '[ 1] 10.44.0.10 on orbit ALLOW IN Anywhere # orbit:wireguard-members'];
        $expected = [];
        foreach ([['https', 'tcp', '443'], ['dns-udp', 'udp', '53'], ['dns-tcp', 'tcp', '53']] as $index => [$family, $protocol, $port]) {
            $comment = 'orbit:router-lan-'.$family.':'.$member->id;
            $lines[] = '[ '.($index + 2)."] 10.20.0.10 {$port}/{$protocol} ALLOW IN 10.20.0.11 # {$comment}";
            $expected[] = [
                'name' => $comment, 'role' => 'router', 'action' => 'allow',
                'source' => '10.20.0.11', 'destination' => '10.20.0.10',
                'port' => $port, 'protocol' => $protocol, 'interface' => null,
            ];
        }
        live_firewall_ssh(implode("\n", $lines)."\n");

        $managed = $this->getJson("/api/v1/nodes/{$router->id}/managed-firewall-rules")->assertOk()->json('data');
        expect(array_slice($managed, 1))->toBe($expected);
        $url = "/api/v1/nodes/{$router->id}/live-firewall-rules";
        $data = $this->getJson($url)->assertOk()->json('data');
        expect(array_column($data['live'], 'match'))->toBe(['exact', 'exact', 'exact', 'exact']);
        expect($data['missing'])->toBe([]);

        $lines[3] = str_replace('53/udp', '54/tcp', $lines[3]);
        $lines[] = '[ 9] 10.20.0.10 53/tcp ALLOW IN 10.20.0.99 # orbit:router-lan-dns-tcp:999';
        live_firewall_ssh(implode("\n", $lines)."\n");
        $data = $this->getJson($url)->assertOk()->json('data');
        expect(array_column($data['live'], 'match'))->toBe(['exact', 'exact', 'drift', 'exact', 'unmanaged']);
    });

    it('returns no live or missing rows when UFW cannot be read', function (): void {
        live_firewall_ssh(throws: true);

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

function live_firewall_ssh(string $stdout = '', bool $throws = false): void
{
    $nodeId = test()->node->id;
    $stdout = str_replace('NODE', (string) $nodeId, $stdout);

    app()->instance(SshExecutor::class, new class($stdout, $throws) implements SshExecutor
    {
        public function __construct(
            private string $stdout,
            private bool $throws,
        ) {}

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            if ($this->throws) {
                throw new RuntimeException('node unreachable');
            }

            return new CommandResult(0, $this->stdout, '', 1, false);
        }
    });
}

<?php

declare(strict_types=1);

use App\Domain\Analytics\AnalyticsRoleSettings;
use App\Domain\Analytics\AnalyticsRoleSettingsRepository;
use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AdmissionAgentRunner;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Nodes\Roles\NativeNodeRoleFirewallManager;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

it('sends only the recovery rule when enabling UFW through public admission', function (): void {
    $agents = new RoleFirewallAgents;
    role_firewall_manager($agents)->convergeBase(role_firewall_node(), 'nckrtl');

    $input = $agents->runner->calls[0]['input'];
    expect($agents->runner->commands())->toBe(['firewall.rules']);
    expect($input)->toMatchArray(['enable' => true, 'remove' => []]);
    expect($input['desired'])->toHaveCount(1)->and($input['desired'][0])->toBe([
        'shape' => ['comment' => 'orbit:public-ssh-recovery', 'action' => 'allow', 'direction' => 'in', 'source' => 'any', 'destination' => 'any', 'port' => '22', 'protocol' => 'tcp', 'in_interface' => null, 'out_interface' => null, 'family' => null],
        'replace_port' => false,
    ]);
    expect($agents->connections[0]->host)->toBe('192.0.2.10')->and($agents->connections[0]->user)->toBe('nckrtl');
});

it('sends private trust and role intent with recovery retirement in one command', function (RoleName $role, array $comments): void {
    $agents = new RoleFirewallAgents;
    role_firewall_manager($agents)->converge(role_firewall_node(), $role, 'nckrtl');

    $input = $agents->runner->calls[0]['input'];
    expect(array_column(array_column($input['desired'], 'shape'), 'comment'))->toBe(['orbit:wireguard-members', ...$comments]);
    expect(array_column($input['remove'], 'comment'))->toContain('orbit:public-ssh-recovery');
    expect($input['enable'])->toBeFalse();
    expect($agents->connections[0]->host)->toBe('10.44.0.2');
    expect($agents->runner->commands())->toBe($role === RoleName::Router ? ['firewall.rules', 'firewall.router-lan'] : ['firewall.rules']);
})->with([
    [RoleName::AppDev, []], [RoleName::AppProd, []], [RoleName::Gateway, ['orbit:gateway-https']],
    [RoleName::Vpn, []], [RoleName::Router, []], [RoleName::CiRunner, ['orbit-ci-runners']],
]);

it('keeps recovery for a roleless member and restores it over WireGuard', function (): void {
    $agents = new RoleFirewallAgents;
    $manager = role_firewall_manager($agents);
    $manager->trustWireGuardMembers(role_firewall_node(), 'nckrtl');
    $manager->restorePublicSsh(role_firewall_node(), 'nckrtl');

    foreach ($agents->runner->calls as $call) {
        expect($call['input']['remove'])->toBe([])->and($call['input']['enable'])->toBeFalse();
    }
    expect($agents->runner->calls[0]['input']['desired'][0]['shape']['comment'])->toBe('orbit:wireguard-members');
    expect($agents->runner->calls[1]['input']['desired'][0]['shape']['comment'])->toBe('orbit:public-ssh-recovery');
    expect(array_map(fn ($c) => $c->host, $agents->connections))->toBe(['10.44.0.2', '10.44.0.2']);
});

it('retires the superseded app listeners without desired public openings', function (RoleName $role, array $retired): void {
    $agents = new RoleFirewallAgents;
    role_firewall_manager($agents)->converge(role_firewall_node(), $role, 'nckrtl');

    $input = $agents->runner->calls[0]['input'];
    expect(array_column($input['remove'], 'comment'))->toContain(...$retired);
    expect(array_column(array_column($input['desired'], 'shape'), 'comment'))->toBe(['orbit:wireguard-members']);
})->with([
    [RoleName::AppDev, ['orbit:vpn-ssh', 'orbit:app-dev-http', 'orbit:app-dev-https', 'orbit:app-dev-direct-http', 'orbit:app-dev-direct-https']],
    [RoleName::AppProd, ['orbit:app-prod-http', 'orbit:app-prod-https']],
    [RoleName::Ingress, ['orbit:ingress-http', 'orbit:ingress-https']],
]);

it('removes all Ingress-owned rules even when no public Route is live', function (): void {
    $agents = new RoleFirewallAgents;
    role_firewall_manager($agents)->remove(role_firewall_node(), RoleName::Ingress, 'nckrtl');

    $input = $agents->runner->calls[0]['input'];
    expect($input['desired'])->toBe([])->and($input['enable'])->toBeFalse();
    expect(array_column($input['remove'], 'comment'))->toBe(['orbit:ingress-http', 'orbit:ingress-https']);
});

it('sends selected Analytics storage ports with the catalog replacement permission', function (): void {
    $storage = analytics_storage_processes();
    $postgres = analytics_connection_process($storage['node'], 'services-postgres', 'postgres:18', ['POSTGRES_PASSWORD' => 'postgres-secret'], ['10.44.0.200:5434:5432/tcp']);
    app(AnalyticsRoleSettingsRepository::class)->store($storage['node'], new AnalyticsRoleSettings($postgres->id, $storage['clickhouse']->id));
    $agents = new RoleFirewallAgents;

    role_firewall_manager($agents)->converge($storage['node'], RoleName::Analytics, 'orbit');

    $rule = array_find($agents->runner->calls[0]['input']['desired'], fn (array $rule): bool => $rule['shape']['comment'] === 'orbit:analytics-postgres-local');
    expect($rule)->toMatchArray(['replace_port' => true]);
    expect($rule['shape'])->toMatchArray(['port' => '5434', 'in_interface' => 'docker0', 'destination' => '10.44.0.200']);
});

it('sends eligible Router family intent and keeps expansion and pruning separate', function (): void {
    [$router, $member] = router_lan_topology();
    $desired = new NodeFirewallRuleCatalog()->routerLanIngress($router);
    $agents = new RoleFirewallAgents;
    $manager = role_firewall_manager($agents);
    $manager->expandRouterLanIngress($router, $desired, 'orbit');
    $manager->pruneRouterLanIngress($router, $desired, 'orbit');
    $manager->remove($router, RoleName::Router, 'orbit');

    expect($agents->runner->commands())->toBe(['firewall.router-lan', 'firewall.router-lan', 'firewall.router-lan']);
    expect(array_column($agents->runner->calls[0]['input']['desired'], 'comment'))->toBe([
        "orbit:router-lan-https:{$member->id}", "orbit:router-lan-dns-udp:{$member->id}", "orbit:router-lan-dns-tcp:{$member->id}",
    ]);
    expect($agents->runner->calls[0]['input'])->toMatchArray(['expand' => true, 'prune' => false]);
    expect($agents->runner->calls[1]['input'])->toMatchArray(['expand' => false, 'prune' => true]);
    expect($agents->runner->calls[2]['input'])->toBe(['desired' => [], 'expand' => false, 'prune' => true]);
});

it('does not observe the Node for an empty expansion', function (): void {
    $agents = new RoleFirewallAgents;
    role_firewall_manager($agents)->expandRouterLanIngress(role_firewall_node(), [], 'orbit');
    expect($agents->connections)->toBe([]);
});

it('maps a failed native Router recovery without claiming convergence', function (): void {
    $agents = new RoleFirewallAgents;
    $agents->runner->refuse('firewall.router-lan', 'router.lan_ingress_recovery_failed');

    try {
        role_firewall_manager($agents)->pruneRouterLanIngress(role_firewall_node(), [], 'orbit');
        test()->fail('Expected a recovery refusal.');
    } catch (FirewallOperationException $failure) {
        expect($failure->errorCode)->toBe('router.lan_ingress_recovery_failed')->and($failure->step)->toBe('host-firewall');
    }
});

it('rejects missing WireGuard addresses before any command', function (): void {
    $node = role_firewall_node();
    $node->wireguard_ip = null;
    $agents = new RoleFirewallAgents;
    expect(fn () => role_firewall_manager($agents)->converge($node, RoleName::Vpn, 'orbit'))->toThrow(FirewallOperationException::class);
    expect($agents->connections)->toBe([]);
});

it('uses the pinned jump connection only for public admission', function (): void {
    $jump = orb197_persisted_firewall_node('jump');
    $node = orb197_persisted_firewall_node('jumped');
    $node->update(['status' => LifecycleStatus::Provisioning, 'ssh_jump_node_id' => $jump->id]);
    $agents = new RoleFirewallAgents;
    role_firewall_manager($agents)->convergeBase($node, 'nckrtl');
    expect($agents->connections[0]->host)->toBe($node->public_ssh_host)->and($agents->connections[0]->proxyJump?->host)->toBe($jump->wireguard_ip);
    role_firewall_manager($agents)->trustWireGuardMembers($node, 'nckrtl');
    expect($agents->connections[1]->host)->toBe($node->wireguard_ip)->and($agents->connections[1]->proxyJump)->toBeNull();
});

function role_firewall_node(): Node
{
    return new Node([
        'name' => 'role-node',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.10',
        'public_ssh_port' => 22,
        'wireguard_ip' => '10.44.0.2',
    ]);
}

function orb197_persisted_firewall_node(string $name): Node
{
    $addressSuffix = Node::query()->count() + 2;

    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "192.0.2.{$addressSuffix}",
        'public_ssh_port' => 22,
        'wireguard_ip' => "10.44.0.{$addressSuffix}",
        'user' => 'nckrtl',
    ]);
}

function role_firewall_manager(RoleFirewallAgents $agents): NativeNodeRoleFirewallManager
{
    $keys = new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/tmp/orbit-test-key';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 TEST';
        }
    };
    $hosts = new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/tmp/orbit-known-hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    };

    return new NativeNodeRoleFirewallManager($agents, $keys, $hosts);
}

final class RoleFirewallAgents implements AdmissionAgentRunner
{
    public FakeAgentCommandRunner $runner;

    /** @var list<SshConnection> */
    public array $connections = [];

    public function __construct()
    {
        $this->runner = new FakeAgentCommandRunner()->answer('firewall.rules', ['matches' => true])->answer('firewall.router-lan', ['matches' => true]);
    }

    public function runOver(Node $node, SshConnection $connection, string $command, array $input = [], ?float $deadlineSeconds = null): array
    {
        $this->connections[] = $connection;

        return $this->runner->run($node, $command, $input, $deadlineSeconds);
    }

    public function architectureOver(Node $node, SshConnection $connection): string
    {
        throw new LogicException('No staging during firewall work.');
    }

    public function installOver(Node $node, SshConnection $connection): bool
    {
        throw new LogicException('Unexpected installation.');
    }
}

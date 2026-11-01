<?php

declare(strict_types=1);

use App\Actions\Firewall\ListManagedFirewallRulesAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\TaskVms\TaskVmHubPolicy;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use Tests\Support\AppDevFakeSshExecutor;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    app(VpnSettings::class)->configure(subnet: '10.44.0.0/24');
    config()->set('task_vms.wireguard_range', '10.44.0.128/25');
    config()->set('task_vms.model_proxy_origin', 'http://10.44.0.3:8317');
    $this->ssh = new AppDevFakeSshExecutor([new CommandResult(0, "{\"ok\":true}\n", 'Rule added', 1, false)]);
    app()->instance(SshExecutor::class, $this->ssh);
});

describe('task-vms:prepare-host', function (): void {
    beforeEach(function (): void {
        $this->agents = new FakeAgentCommandRunner;
        $this->agents->answer('incus.prepare-host', ['ready' => true]);
        app()->instance(AgentCommandRunner::class, $this->agents);
    });
    it('sends the host settings to the native preparation command', function (): void {
        $beast = task_vm_fleet_node('beast', '10.44.0.7', [RoleName::AppDev]);
        task_vm_node_hosts([[
            'node_id' => $beast->id,
            'cidr' => '10.252.0.0/24',
            'pool' => 'orbit-e2e',
            'max_vms' => 4,
        ]]);

        $this->artisan('task-vms:prepare-host', ['node' => 'beast'])
            ->expectsOutputToContain('Node [beast] is ready for task VMs on bridge [orbittask0]. Build its images with task-vms:build-image.')
            ->assertSuccessful();

        expect($this->agents->calls)->toBe([['node' => 'beast', 'command' => 'incus.prepare-host', 'input' => [
            'project' => 'orbit-tasks', 'network' => 'orbittask0', 'cidr' => '10.252.0.0/24',
            'pool' => 'orbit-e2e', 'zfs_dataset' => null,
        ]]]);
        expect($this->ssh->commands)->toBe([]);
    });

    it('passes the ZFS dataset that creates a missing pool', function (): void {
        $beast = task_vm_fleet_node('beast', '10.44.0.7', [RoleName::AppDev]);
        task_vm_node_hosts([['node_id' => $beast->id, 'cidr' => '10.252.0.0/24', 'max_vms' => 4, 'zfs_dataset' => 'fast/orbit-tasks']]);

        $this->artisan('task-vms:prepare-host', ['node' => 'beast'])->assertSuccessful();

        expect($this->agents->calls[0]['input']['zfs_dataset'])->toBe('fast/orbit-tasks');
    });

    it('refuses invalid host settings before it connects', function (array $host, string $message): void {
        $beast = task_vm_fleet_node('beast', '10.44.0.7', [RoleName::AppDev]);
        task_vm_node_hosts([['node_id' => $beast->id, 'cidr' => '10.252.0.0/24', 'max_vms' => 4, ...$host]]);

        $this->artisan('task-vms:prepare-host', ['node' => (string) $beast->id])
            ->expectsOutputToContain($message)
            ->assertFailed();

        expect($this->ssh->commands)->toBe([]);
    })->with([
        'network outside the reserved prefix' => [['network' => 'incusbr0'], '[task_vm.invalid_config] The task VM config is invalid: incus.hosts.0.network is invalid.'],
        'cidr overlapping the fleet' => [['cidr' => '10.44.0.0/16'], 'incus.hosts.0.cidr must not overlap the VPN subnet [10.44.0.0/24].'],
    ]);

    it('refuses a Node that is not a configured task VM host', function (): void {
        task_vm_fleet_node('shark', '10.44.0.11', [RoleName::AppDev]);

        $this->artisan('task-vms:prepare-host', ['node' => 'shark'])
            ->expectsOutputToContain('[task_vm.unknown_host]')
            ->assertFailed();

        expect($this->ssh->commands)->toBe([]);
    });

    it('reports the native setup refusal', function (): void {
        $beast = task_vm_fleet_node('beast', '10.44.0.7', [RoleName::AppDev]);
        task_vm_node_hosts([['node_id' => $beast->id, 'cidr' => '10.252.0.0/24', 'max_vms' => 4]]);
        $this->agents->refuse('incus.prepare-host', 'task_vm.setup_failed', ['stage' => 'prerequisites', 'reason' => 'ufw is not installed.']);

        $this->artisan('task-vms:prepare-host', ['node' => 'beast'])
            ->expectsOutputToContain('[task_vm.setup_failed] Task VM host setup failed on Node [beast]: ufw is not installed.')
            ->assertFailed();
    });
});

describe('task-vms:prepare-hub', function (): void {
    beforeEach(function (): void {
        $this->agents = new FakeAgentCommandRunner;
        app()->instance(AgentCommandRunner::class, $this->agents);
        $this->hub = task_vm_fleet_node('vpn', '10.44.0.1', [RoleName::Vpn], user: 'orbit');
        task_vm_fleet_node('gateway', '10.44.0.2', [RoleName::Gateway]);
        task_vm_fleet_node('services', '10.44.0.3', [RoleName::WebSocket, RoleName::AppDev]);
        $cluster = Cluster::query()->create(['name' => 'development']);
        $router = task_vm_fleet_node('beast', '10.44.0.7', [RoleName::AppDev], cluster: $cluster);
        NodeRole::query()->create([
            'node_id' => $router->id,
            'cluster_id' => $cluster->id,
            'role' => RoleName::Router,
            'status' => LifecycleStatus::Active,
        ]);
        config()->set('task_vms.dev_cluster_id', $cluster->id);
        Node::query()->forceCreate(['id' => 209, 'name' => 'swamp', 'wireguard_ip' => '10.44.0.15', 'public_ssh_host' => '192.0.2.209', 'user' => 'orbit', 'status' => 'active', 'platform' => 'linux']);
        $this->hub->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']);
        config(['swamp.run_token' => 'fake.token']);
        $socket = new FakeRoutePublicationSocket;
        $socket->reply = fn (array $sent): array => [['type' => 'event', 'id' => $sent['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-task-vm-hub-converge', 'runId' => '07491a2f-25a8-4458-92dd-e58e467ac28c']]];
        app()->instance(WebSocketClient::class, $socket);
    });

    it('sends typed hub endpoints derived from the fleet', function (): void {
        $this->artisan('task-vms:prepare-hub')
            ->expectsOutputToContain('Submitted hub [vpn] filter for [10.44.0.128/25] as Operation [')
            ->assertSuccessful();

        $intent = Operation::query()->where('kind', 'task_vm.hub_converge')->sole()->input['intent'];
        expect($intent['policy'])->toBe([
            'range' => '10.44.0.128/25', 'dns' => '10.44.0.1', 'gateway' => '10.44.0.2',
            'pi_port' => 3774, 'reverb' => '10.44.0.3:443', 'model' => '10.44.0.3:8317', 'routers' => ['10.44.0.7'],
        ])->and($intent['ssh']['control_node_id'])->toBe(209)
            ->and($intent['nft'])->toContain('ip saddr 10.44.0.15 ip daddr 10.44.0.128/25 tcp dport 22 accept comment "orbit:task-vm-control-ssh"')
            ->and($intent['nft'])->not->toContain('ip saddr 10.44.0.15 ip daddr 10.44.0.128/25 tcp dport {')
            ->and($this->agents->calls)->toBe([]);
        expect($this->ssh->commands)->toBe([]);
    });

    it('shows the same narrow control rule in the managed firewall view', function (): void {
        $rows = app(ListManagedFirewallRulesAction::class)->execute($this->hub);
        $row = array_find($rows, static fn (array $row): bool => $row['name'] === 'orbit:task-vm-control-ssh');
        expect($row)->toMatchArray(['role' => 'vpn', 'source' => '10.44.0.15', 'destination' => '10.44.0.128/25', 'port' => '22', 'protocol' => 'tcp', 'interface' => 'orbit']);
        Node::query()->findOrFail(209)->update(['wireguard_ip' => '10.44.0.16']);
        $rule = app(TaskVmHubPolicy::class)->controlRule();
        expect($rule?->shape->source)->toBe('10.44.0.16');
    });

    it('preserves Gateway Pi router and isolation policy around the added SSH rule', function (): void {
        $this->artisan('task-vms:prepare-hub')->assertSuccessful();
        $text = Operation::query()->where('kind', 'task_vm.hub_converge')->sole()->input['intent']['nft'];
        foreach (['ip saddr 10.44.0.2 ip daddr 10.44.0.128/25 tcp dport { 22, 3774 } accept',
            'ip saddr 10.44.0.7 ip daddr 10.44.0.128/25 tcp dport 443 accept comment "orbit:task-vm-router-web-10-44-0-7-443"',
            'ct state established,related accept', 'ip saddr 10.44.0.128/25 drop', 'ip daddr 10.44.0.128/25 drop',
            'ip daddr 10.44.0.1 udp dport 53 accept', 'ip daddr 10.44.0.3 tcp dport 8317 accept'] as $retained) {
            expect($text)->toContain($retained);
        }
        expect(strpos($text, 'ip saddr 10.44.0.15'))->toBeLessThan(strpos($text, 'ip saddr 10.44.0.128/25 drop'));
    });

    it('keeps the control SSH catalog without Router assignments or complete image settings', function (): void {
        NodeRole::query()->where('role', RoleName::Router)->delete();
        config(['task_vms.dev_cluster_id' => (string) config('task_vms.dev_cluster_id'),
            'task_vms.pi.artifact_path' => '/incomplete/image-only-setting']);
        $rules = app(TaskVmHubPolicy::class)->managedRules();
        expect(array_map(static fn ($rule): string => $rule->shape->comment, $rules))->toBe([TaskVmHubPolicy::Comment]);
    });

    it('admits every recorded Router in the task VM Cluster and exposes only their web rules', function (): void {
        $cluster = Cluster::query()->findOrFail(config('task_vms.dev_cluster_id'));
        config(['task_vms.dev_cluster_id' => (string) $cluster->id]);
        $candidate = task_vm_fleet_node('candidate', '10.44.0.11', [RoleName::AppDev], cluster: $cluster);
        NodeRole::query()->create(['node_id' => $candidate->id, 'cluster_id' => $cluster->id,
            'role' => RoleName::Router, 'status' => LifecycleStatus::Provisioning]);
        $other = Cluster::query()->create(['name' => 'other']);
        $foreign = task_vm_fleet_node('other-router', '10.44.0.42', [], cluster: $other);
        NodeRole::query()->create(['node_id' => $foreign->id, 'cluster_id' => $other->id,
            'role' => RoleName::Router, 'status' => LifecycleStatus::Active]);
        task_vm_fleet_node('ordinary-member', '10.44.0.43', [RoleName::AppDev], cluster: $cluster);
        $inactive = task_vm_fleet_node('inactive-router', '10.44.0.44', [], cluster: $cluster);
        $inactive->update(['status' => LifecycleStatus::Failed]);
        NodeRole::query()->create(['node_id' => $inactive->id, 'cluster_id' => $cluster->id,
            'role' => RoleName::Router, 'status' => LifecycleStatus::Provisioning]);

        $this->artisan('task-vms:prepare-hub')->assertSuccessful();

        $intent = Operation::query()->where('kind', 'task_vm.hub_converge')->sole()->input['intent'];
        expect($intent['policy']['routers'])->toBe(['10.44.0.7', '10.44.0.11']);
        $rows = array_values(array_filter(app(ListManagedFirewallRulesAction::class)->execute($this->hub),
            static fn (array $row): bool => str_starts_with($row['name'], 'orbit:task-vm-router-web-')));
        expect(array_map(static fn (array $row): array => [$row['source'], $row['destination'], $row['port'], $row['protocol']], $rows))->toBe([
            ['10.44.0.7', '10.44.0.128/25', '80', 'tcp'],
            ['10.44.0.7', '10.44.0.128/25', '443', 'tcp'],
            ['10.44.0.11', '10.44.0.128/25', '80', 'tcp'],
            ['10.44.0.11', '10.44.0.128/25', '443', 'tcp'],
        ]);
        expect($intent['router_rules'])->toHaveCount(4);
        foreach ($rows as $row) {
            expect($intent['nft'])->toContain('ip saddr '.$row['source'].' ip daddr '.$row['destination'].' tcp dport '.$row['port'].' accept comment "'.$row['name'].'"');
        }
        expect($intent['nft'])->not->toContain('5173', '10.44.0.42', '10.44.0.43', '10.44.0.44');
    });

    it('passes the configured VPN DNS address, Pi port, and default origin port', function (): void {
        app(VpnSettings::class)->configure(subnet: '10.44.0.0/24', dnsServer: '10.44.0.53');
        config()->set('orbit.pi.port', 4774);
        config()->set('task_vms.model_proxy_origin', 'https://10.44.0.3');

        $this->artisan('task-vms:prepare-hub')->assertSuccessful();

        expect(Operation::query()->where('kind', 'task_vm.hub_converge')->sole()->input['intent']['policy'])->toBe([
            'range' => '10.44.0.128/25', 'dns' => '10.44.0.53', 'gateway' => '10.44.0.2',
            'pi_port' => 4774, 'reverb' => '10.44.0.3:443', 'model' => '10.44.0.3:443', 'routers' => ['10.44.0.7'],
        ]);
    });

    it('refuses missing control identity or target pins before admission', function (bool $control): void {
        if ($control) {
            Node::query()->findOrFail(209)->update(['status' => LifecycleStatus::Failed]);
        } else {
            $this->hub->update(['ssh_host_key' => null]);
        }
        $this->artisan('task-vms:prepare-hub')->expectsOutputToContain($control ? '[task_vm.fleet_unavailable]' : '[swamp.ssh_target_unavailable]')->assertFailed();
        expect(Operation::query()->where('kind', 'task_vm.hub_converge')->exists())->toBeFalse()
            ->and($this->agents->calls)->toBe([])->and($this->ssh->commands)->toBe([]);
    })->with([true, false]);

    it('preserves the hub filter reserved-prefix validation before admission', function (): void {
        config()->set('task_vms.wireguard_range', '10.44.0.128/31');
        $this->artisan('task-vms:prepare-hub')->expectsOutputToContain('[task_vm.invalid_config]')->assertFailed();
        expect(Operation::query()->where('kind', 'task_vm.hub_converge')->exists())->toBeFalse();
    });

    it('allows task VM Nodes inside the range', function (): void {
        task_vm_fleet_node('tvm-12', '10.44.0.130', [RoleName::AppDev]);

        $this->artisan('task-vms:prepare-hub')->assertSuccessful();
    });

    it('refuses a range or fleet that the filter could break', function (Closure $arrange, string $message): void {
        $arrange();

        $this->artisan('task-vms:prepare-hub')
            ->expectsOutputToContain($message)
            ->assertFailed();

        expect($this->agents->calls)->toBe([]);
        expect($this->ssh->commands)->toBe([]);
    })->with([
        'range outside the WireGuard subnet' => [
            fn () => config()->set('task_vms.wireguard_range', '10.45.0.0/25'),
            '[task_vm.invalid_config] The task VM config is invalid: wireguard_range must be a smaller network inside the VPN subnet [10.44.0.0/24].',
        ],
        'range that covers the whole subnet' => [
            fn () => config()->set('task_vms.wireguard_range', '10.44.0.0/23'),
            'wireguard_range must be a smaller network inside the VPN subnet',
        ],
        'fleet Node inside the range' => [
            fn () => task_vm_fleet_node('laptop', '10.44.0.200', []),
            '[task_vm.range_in_use] Node [laptop] holds [10.44.0.200] inside [10.44.0.128/25].',
        ],
        'model proxy inside the range' => [
            fn () => config()->set('task_vms.model_proxy_origin', 'http://10.44.0.140:8317'),
            '[task_vm.invalid_config] The task VM config is invalid: model_proxy_origin [http://10.44.0.140:8317] must name an IPv4 address outside [10.44.0.128/25].',
        ],
        'model proxy by name' => [
            fn () => config()->set('task_vms.model_proxy_origin', 'http://models.orbit:8317'),
            '[task_vm.invalid_config] The task VM config is invalid: model_proxy_origin [http://models.orbit:8317] must name an IPv4 address',
        ],
        'no model proxy' => [
            fn () => config()->set('task_vms.model_proxy_origin', null),
            '[task_vm.invalid_config] The task VM config is invalid: model_proxy_origin is not set.',
        ],
        'no dev Cluster' => [
            fn () => config()->set('task_vms.dev_cluster_id', null),
            '[task_vm.invalid_config] The task VM config is invalid: dev_cluster_id is not set.',
        ],
        'missing dev Cluster' => [
            fn () => config()->set('task_vms.dev_cluster_id', 999),
            '[task_vm.fleet_unavailable] Cluster [999] has no active Router-role Node.',
        ],
        'second websocket Node' => [
            fn () => task_vm_fleet_node('reverb2', '10.44.0.20', [RoleName::WebSocket]),
            '[task_vm.fleet_unavailable] Expected exactly one active [websocket] Node, found [2].',
        ],
    ]);
});

/** @param list<RoleName> $roles */
function task_vm_fleet_node(string $name, string $wireguardIp, array $roles, string $user = 'nckrtl', ?Cluster $cluster = null): Node
{
    $node = Node::query()->create([
        'cluster_id' => $cluster?->id,
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.'.substr($wireguardIp, strrpos($wireguardIp, '.') + 1),
        'user' => $user,
        'wireguard_ip' => $wireguardIp,
    ]);

    foreach ($roles as $role) {
        NodeRole::query()->create(['node_id' => $node->id, 'role' => $role, 'status' => LifecycleStatus::Active]);
    }

    return $node;
}

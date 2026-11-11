<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Nodes\GatewayPrivateDnsInput;
use App\Domain\Nodes\GatewayPrivateDnsState;
use App\Domain\Nodes\GatewayRuntimeFiles;
use App\Domain\Nodes\NativeNodeRoleAddInput;
use App\Domain\Nodes\NativeNodeRoleRemovalInput;
use App\Domain\Nodes\NativeNodeRoleRuntimeInput;
use App\Domain\Nodes\NodeDesiredConvergeInput;
use App\Domain\Nodes\NodeRoleDirectInput;
use App\Domain\Nodes\NodeRoleRemovalProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Nodes\NodePrivateDnsListenerFootprint;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\TestOrbitHome;

uses(LazilyRefreshDatabase::class);

it('converges the Gateway runtime with the gateway role and retires the release unit and the start hook', function (): void {
    config(['orbit.gateway_checkout' => '/home/orbit/orbit/apps/gateway', 'orbit.home' => '/home/orbit/.orbit', 'orbit.hibernation.sweep_seconds' => 600]);
    $node = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2',
        'user' => 'orbit', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']));
    $role = $node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $operation = Operation::query()->create(['kind' => 'node.role_converge', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'status' => 'running', 'input' => [], 'triggered_by' => 'test']);

    $runtime = app(NativeNodeRoleRuntimeInput::class)->capture($node, $role, ['gateway_dns' => null, 'runtime' => null], $operation)['intent'];
    $files = collect($runtime['files'])->keyBy('path');

    expect($runtime['gateway'])->toBe(['pool' => GatewayRuntimeFiles::Pool, 'release_units' => GatewayRuntimeFiles::ReleaseUnits, 'account' => 'orbit',
        'environment' => GatewayReleaseLayout::fromConfig()->sharedPath().'/gateway.env',
        'retired_settings' => ['ORBIT_TASK_VMS_UPCLOUD_TOKEN_FILE', 'ORBIT_TASK_VMS_UPCLOUD_MAX_VMS', 'ORBIT_TASK_VMS_UPCLOUD_PLAN', 'ORBIT_TASK_VMS_UPCLOUD_DISK_GB'],
        'tmpfiles' => '/etc/tmpfiles.d/orbit-gateway.conf'])
        ->and($files['/etc/tmpfiles.d/orbit-gateway.conf'])->toMatchArray(['ensure' => 'present', 'owner' => 'root', 'group' => 'root', 'mode' => '0644'])
        ->and($files['/etc/tmpfiles.d/orbit-gateway.conf']['content'])->toEndWith("\nd /run/orbit 0755 orbit orbit -\n")
        ->and($files[GatewayRuntimeFiles::Pool]['content'])->toContain("[orbit-gateway]\n", "chdir = /home/orbit/orbit/apps/gateway\n", "env[ORBIT_HOME] = /home/orbit/.orbit\n")
        ->and($files['/etc/systemd/system/orbit-runtime-hibernator.service']['content'])->toContain("ExecStart=\"/usr/bin/php8.5\" \"/home/orbit/orbit/apps/gateway/artisan\" \"orbit:runtime-hibernator\"\n")
        ->and($files['/etc/systemd/journald.conf.d/orbit-retention.conf']['content'])->toContain("SystemMaxUse=256M\nSystemKeepFree=1024M\n")
        ->and($files->filter(static fn (array $file): bool => $file['ensure'] === 'absent')->keys()->all())->toBe([
            '/etc/systemd/system/orbit-gateway-release.timer', '/etc/systemd/system/orbit-gateway-release.service',
            '/etc/systemd/system/orbit-gateway-release-run@.service', '/etc/systemd/system/php8.5-fpm.service.d/orbit-document-cleanup.conf',
            '/etc/orbit/php-fpm-start-hooks'])
        ->and(array_column($runtime['directories'], 'path'))->toBe(['/etc/systemd/journald.conf.d', '/etc/systemd/system/logrotate.timer.d'])
        ->and(array_column($runtime['units'], 'name'))->toBe(['orbit-agent-view.service', 'orbit-gateway-schedule.service', 'orbit-runtime-hibernator.timer'])
        ->and($runtime['units'][2]['unit'])->toContain("OnUnitActiveSec=600s\n");
});

it('escapes configured paths in the units and the logrotate rule as the agent wrote them', function (): void {
    config(['orbit.gateway_checkout' => '/srv/orbit 50%/orbit/apps/gateway', 'orbit.home' => '/srv/Orbit "State"']);
    $runtime = app(GatewayRuntimeFiles::class)->render();
    $files = collect($runtime['files'])->keyBy('path');

    expect($runtime['units'][0]['unit'])->toContain(
        "WorkingDirectory=/srv/orbit\\x2050%%/orbit/apps/gateway\n",
        "Environment=ORBIT_HOME=/srv/Orbit\\x20\\x22State\\x22\n",
        "ExecStart=\"/usr/bin/php8.5\" \"/srv/orbit 50%%/orbit/apps/gateway/artisan\" \"orbit:agent-view\"\n",
    )->and($files['/etc/logrotate.d/orbit-gateway']['content'])->toContain("\"/srv/orbit 50%/orbit/apps/gateway/storage/logs/laravel.log\" {\n");
});

it('runs the scheduler and, with a queue, Horizon as units unless the Node has the Processes', function (): void {
    config(['orbit.gateway_checkout' => '/home/orbit/orbit/apps/gateway', 'orbit.home' => '/home/orbit/.orbit', 'orbit.hibernation.sweep_seconds' => 600]);
    $node = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2',
        'user' => 'orbit', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']));
    $names = static fn (): array => array_column(app(GatewayRuntimeFiles::class)->render($node)['units'], 'name');

    expect($names())->not->toContain('orbit-gateway-horizon.service')
        ->and($names())->toContain('orbit-gateway-schedule.service');

    config(['orbit.gateway_queue.connection' => 'gateway-queue']);
    $units = collect(app(GatewayRuntimeFiles::class)->render($node)['units'])->keyBy('name');

    expect($units['orbit-gateway-horizon.service']['unit'])->toContain("After=network-online.target valkey-server.service\n", "ExecStart=\"/usr/bin/php8.5\" \"/home/orbit/orbit/apps/gateway/artisan\" \"horizon\"\n", "Restart=always\n",
        "KillMode=mixed\nKillSignal=SIGTERM\nSendSIGKILL=no\nTimeoutStopSec=")
        ->and($units['orbit-gateway-schedule.service']['unit'])->toContain('"schedule:work"')->not->toContain('KillMode')->not->toContain('RuntimeDirectory')
        ->and($units['orbit-gateway-horizon.service']['unit'])->not->toContain('RuntimeDirectory');

    foreach (['horizon', 'schedule-work'] as $name) {
        Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => $name, 'runtime' => 'systemd', 'runtime_config' => ['command' => ['true']]]);
    }

    expect($names())->toBe(['orbit-agent-view.service', 'orbit-runtime-hibernator.timer']);
});

it('captures the shared local DNS listener and Gateway catalog before resolver routing', function (?LifecycleStatus $vpnStatus): void {
    $address = '10.45.0.1';
    config(['orbit.gateway_checkout' => '/home/orbit/orbit/apps/gateway', 'orbit.home' => '/home/orbit/.orbit']);
    $node = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => $address,
        'user' => 'orbit', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']));
    $role = $node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    if ($vpnStatus !== null) {
        $node->roles()->create(['role' => RoleName::Vpn, 'status' => $vpnStatus]);
    }
    $operation = Operation::query()->create(['kind' => 'node.role_converge', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'status' => 'running', 'input' => [], 'triggered_by' => 'test']);

    $node->roles()->create(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Active]);

    $dns = app(NativeNodeRoleRuntimeInput::class)->capture($node, $role, ['gateway_dns' => app(GatewayPrivateDnsInput::class)->capture($node), 'runtime' => null], $operation)['intent']['gateway_dns'];
    expect($dns)->toHaveKey('listener');
    $listener = $dns['listener'];
    $catalog = json_decode($listener['catalog'], true, flags: JSON_THROW_ON_ERROR);

    expect($dns['unit'])->toContain("ExecStart=/usr/bin/resolvectl dns orbit {$address}\n", 'ConditionPathExists=!/etc/wireguard/orbit.dns-link');
    expect($catalog['records']['gateway.orbit'])->toBe($address);
    expect($catalog['records']['reverb.orbit'])->toBe($address);
    expect($listener['catalog_sha256'])->toBe(hash('sha256', $listener['catalog']))
        ->and($listener['address'])->toBe($address)
        ->and($listener['socket'])->toContain("ListenDatagram={$address}:53\n", "ListenStream={$address}:53\n")
        ->and($listener['service'])->toContain('Sockets=orbit-private-dns.socket', '"--catalog=/var/lib/orbit/private-dns/catalog.json"',
            '"--upstream=127.0.0.55:53"')
        ->and(array_column($listener['files'], 'path'))->toContain($listener['release'].'/serve.php');

    if ($vpnStatus !== null) {
        $footprint = app(NodePrivateDnsListenerFootprint::class)->input($node);
        expect($listener['service'])->toBe($footprint['service'])
            ->and($listener['socket'])->toBe($footprint['socket']);
    }
})->with([
    'nested fleet without a VPN Node' => [null],
    'stock Gateway before its first VPN publication' => [LifecycleStatus::Provisioning],
    'active colocated VPN keeps the same listener owner' => [LifecycleStatus::Active],
]);

it('withdraws the Gateway listener only when no colocated VPN needs it', function (bool $vpn, bool $separate, bool $owned): void {
    config(['swamp.ssh_public_key' => 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32)).' control']);
    $node = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture',
        'user' => 'orbit', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']));
    $role = $node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Removing]);
    $node->roles()->create(['role' => RoleName::Swamp, 'status' => LifecycleStatus::Active]);
    if ($vpn) {
        $node->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);
    }
    if ($separate) {
        $resolver = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => 'vpn', 'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture']));
        $resolver->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);
    }
    if ($owned) {
        app(GatewayPrivateDnsState::class)->claim($node->id, ['gateway_dns' => ['listener' => []]]);
    }
    $capture = app(NativeNodeRoleRemovalInput::class);
    $before = $capture->publications($node, RoleName::Gateway);
    $plan = $capture->capture($node, $role, $before);

    $units = array_column(array_column($plan['stop_units'], 'input'), 'unit');
    expect($units)->toBe(($vpn || ! $owned) ? ['orbit-gateway-dns.service'] : ['orbit-gateway-dns.service', 'orbit-private-dns.socket', 'orbit-private-dns.service']);
    expect(array_column(array_column($plan['remove_units'], 'input'), 'unit'))->toBe($units);
    $ownedFiles = array_values(array_filter(array_column($plan['files'], 'path'), static fn (string $path): bool => str_starts_with($path, '/var/lib/orbit/private-dns/')));
    $ownedDirectories = array_values(array_filter(array_column($plan['directories'], 'path'), static fn (string $path): bool => str_starts_with($path, '/var/lib/orbit/private-dns/')));
    expect($ownedFiles)->toBe(($vpn || ! $owned) ? [] : ['/var/lib/orbit/private-dns/catalog.json', '/var/lib/orbit/private-dns/catalog.json.loaded']);
    expect($ownedDirectories)->toBe(($vpn || ! $owned) ? [] : ['/var/lib/orbit/private-dns/releases']);
    NodeRoleRemovalProjection::recordCleanup(['node_id' => $node->id, 'role' => 'gateway']);
    expect(app(GatewayPrivateDnsState::class)->owned($node->id))->toBeFalse();
})->with(['Gateway-only' => [false, false, true], 'Gateway and VPN' => [true, false, true],
    'production Gateway with separate VPN' => [false, true, false], 'owned listener with a newly enrolled VPN' => [false, true, true]]);

it('captures Gateway DNS without a configured DNS server or a separate VPN Node', function (): void {
    config(['swamp.ssh_public_key' => 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32)).' control']);
    $node = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.45.0.1',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture',
        'user' => 'orbit', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']));
    $role = $node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $node->roles()->create(['role' => RoleName::Swamp, 'status' => LifecycleStatus::Active]);

    $snapshot = app(NodeRoleDirectInput::class)->capture($node, $role, false);

    expect($snapshot['gateway_dns'])->toMatchArray(['operation' => 'converge', 'address' => '10.45.0.1', 'domain' => 'orbit'])
        ->toHaveKey('listener');
});

it('adds zero packages to the captured Gateway recipe and role inputs when local DNS is selected', function (bool $converge): void {
    config(['swamp.address' => 'swamp.orbit', 'swamp.ca_path' => '/fixture/ca.pem', 'swamp.run_token' => 'fixture',
        'swamp.ssh_public_key' => 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32)).' control']);
    app()->instance(LeafCertificateSigner::class, new class implements LeafCertificateSigner
    {
        public function rootCertificate(): string
        {
            return "fixture root\n";
        }
    });
    $frame = null;
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->andReturnUsing(function (array $sent) use (&$frame): void {
        $frame = $sent;
    });
    $socket->shouldReceive('receive')->andReturnUsing(static function () use (&$frame): array {
        if (! isset($frame['id'], $frame['payload']['vaultName'], $frame['payload']['key'])) {
            throw new LogicException('The fixture expects a vault.put frame before its response.');
        }

        return [['type' => 'vault.put', 'id' => $frame['id'], 'payload' => ['data' => ['vaultName' => $frame['payload']['vaultName'], 'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
    });
    app()->instance(WebSocketClient::class, $socket);
    $node = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => 'gateway', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.45.0.1',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture', 'agent_secret_hash' => str_repeat('a', 64),
        'user' => 'orbit', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']));
    $assignment = $node->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $node->roles()->create(['role' => RoleName::Swamp, 'status' => LifecycleStatus::Active]);
    $vpn = Node::withoutEvents(static fn (): Node => Node::query()->create(['name' => 'vpn', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.45.0.2',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture',
        'user' => 'orbit', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']));
    $vpn->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);
    $operation = Operation::query()->create(['kind' => 'node.converge', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'status' => 'running', 'input' => [], 'triggered_by' => 'test']);
    $roleCapture = fn (): array => app(NativeNodeRoleAddInput::class)->capture($node, $assignment, app(NodeRoleDirectInput::class)->capture($node, $assignment, false), [], $operation, $converge)['intent'];
    $roleWithout = $roleCapture();
    $capture = app(NodeDesiredConvergeInput::class);
    $without = $capture->capture($node, ['gateway'], false, $operation, null)['intent'];
    expect($without)->not->toHaveKey('playbook');
    $vpn->roles()->delete();
    $with = $capture->capture($node, ['gateway'], false, $operation, null)['intent'];
    $roleWith = $roleCapture();
    expect($with['playbook'])->toBe('operation-node-converge-local-dns.yml');
    expect($with['recipe'])->toBe($without['recipe']);
    expect($roleWith['packages'])->toBe($roleWithout['packages']);
    expect($with)->toHaveKey('roles.0.packages', $roleWithout['packages']);
    expect($without)->toHaveKey('roles.0.packages', $roleWithout['packages']);
})->with(['role add' => false, 'role converge' => true]);

it('keeps production Gateway runtime recipe and Ansible vars byte identical to main', function (LifecycleStatus $vpnNodeStatus, LifecycleStatus $vpnRoleStatus): void {
    config(['orbit.gateway_checkout' => '/home/orbit/orbit/apps/gateway', 'orbit.home' => '/home/orbit/.orbit',
        'orbit.hibernation.sweep_seconds' => 600, 'orbit.gateway_queue.connection' => null,
        'swamp.address' => 'swamp.orbit', 'swamp.ca_path' => '/fixture/ca.pem', 'swamp.run_token' => 'fixture',
        'swamp.ssh_public_key' => 'ssh-ed25519 '.base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32)).' control']);
    $this->mock(LeafCertificateSigner::class, fn ($mock) => $mock->shouldReceive('rootCertificate')->andReturn("fixture root\n"));
    $frame = null;
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->andReturnUsing(function (array $sent) use (&$frame): void {
        $frame = $sent;
    });
    $socket->shouldReceive('receive')->andReturnUsing(static function () use (&$frame): array {
        return [['type' => 'vault.put', 'id' => $frame['id'], 'payload' => ['data' => ['vaultName' => $frame['payload']['vaultName'], 'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
    });
    app()->instance(WebSocketClient::class, $socket);
    $node = Node::withoutEvents(static fn (): Node => Node::query()->create(['id' => 14, 'name' => 'gateway', 'public_ssh_host' => '94.237.46.255', 'wireguard_ip' => '10.44.0.2',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'gateway-fixture', 'agent_secret_hash' => str_repeat('a', 64),
        'user' => 'orbit', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']));
    $node->roles()->create(['id' => 1, 'role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $vpn = Node::withoutEvents(static fn (): Node => Node::query()->create(['id' => 1, 'name' => 'vpn', 'public_ssh_host' => '94.237.124.160', 'wireguard_ip' => '10.44.0.1',
        'user' => 'orbit', 'status' => $vpnNodeStatus, 'platform' => 'linux', 'architecture' => 'x86_64', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'vpn-fixture']));
    $vpn->roles()->create(['id' => 2, 'role' => RoleName::Vpn, 'status' => $vpnRoleStatus]);
    $control = Node::withoutEvents(static fn (): Node => Node::query()->create(['id' => 209, 'name' => 'swamp', 'public_ssh_host' => '80.47.233.206', 'wireguard_ip' => '10.44.0.15',
        'user' => 'orbit', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64']));
    $control->roles()->create(['id' => 3, 'role' => RoleName::Swamp, 'status' => LifecycleStatus::Active]);
    $operation = Operation::query()->create(['id' => 701, 'kind' => 'node.converge', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'status' => 'running', 'input' => [], 'triggered_by' => 'test']);

    $capture = app(NodeDesiredConvergeInput::class)->capture($node, ['gateway'], false, $operation, null);
    $role = $capture['intent']['roles'][0];
    $folder = TestOrbitHome::scratch('production-gateway-equality');
    mkdir($folder, 0700, true);
    file_put_contents($folder.'/intent.json', json_encode($capture['intent'], JSON_THROW_ON_ERROR));
    file_put_contents($folder.'/plans.json', '{}');
    $materializer = new Symfony\Component\Process\Process(['deno', 'eval',
        'import { convergeInput } from '.json_encode('file://'.base_path('../../swamp/extensions/behaviors/operations/ansible-input/converge_input.ts'), JSON_THROW_ON_ERROR).'; await convergeInput(Deno.args[0], Deno.args[1], "/fixture/converge_tool.ts");',
        $folder, base_path('../../swamp/ansible/build_vars.py')]);
    $materializer->mustRun();
    $hash = static fn (mixed $value): string => hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    $actual = ['runtime_sha256' => $hash($role['runtime']), 'role_sha256' => $hash($role),
        'recipe_sha256' => hash('sha256', $capture['intent']['recipe']), 'converge_sha256' => $hash($capture),
        'ansible_vars_sha256' => hash_file('sha256', $folder.'/vars.yml'),
        'role_ansible_vars_sha256' => hash_file('sha256', $folder.'/role-'.$role['assignment_id'].'/vars.yml'),
        'gateway_dns' => $role['runtime']['gateway_dns']];
    if (is_string($evidence = getenv('ORBIT_DNS_EQUALITY_EVIDENCE')) && $evidence !== '') {
        file_put_contents($evidence.'-'.$vpnNodeStatus->value.'-'.$vpnRoleStatus->value.'.json', json_encode($actual, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
    $golden = json_decode(file_get_contents(base_path('tests/Fixtures/production-gateway-converge-main.json')), true, flags: JSON_THROW_ON_ERROR);
    expect($actual)->toBe($golden['vpn_states'][$vpnNodeStatus->value.'/'.$vpnRoleStatus->value]);
    expect($capture['intent'])->not->toHaveKey('playbook');
    Node::withoutEvents(fn (): bool => $vpn->update(['status' => LifecycleStatus::Active]));
    $vpn->roles()->delete();
    $local = app(NodeDesiredConvergeInput::class)->capture($node, ['gateway'], false, $operation, null);
    expect($local['intent']['playbook'])->toBe('operation-node-converge-local-dns.yml')
        ->and($local['intent']['roles'][0]['playbook'])->toBe('operation-node-role-add-local-dns.yml');
    app(GatewayPrivateDnsState::class)->claim($node->id, $local['intent']['roles'][0]['runtime']);
    $vpn->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);
    $withdraw = app(NodeDesiredConvergeInput::class)->capture($node, ['gateway'], false, $operation, null);
    expect($withdraw['intent']['playbook'])->toBe('operation-node-converge-local-dns.yml')
        ->and($withdraw['intent']['roles'][0]['runtime']['gateway_dns'])->toHaveKey('withdraw');
})->with([
    'healthy VPN owner' => [LifecycleStatus::Active, LifecycleStatus::Active],
    'failed VPN Node' => [LifecycleStatus::Failed, LifecycleStatus::Active],
    'removing VPN Node' => [LifecycleStatus::Removing, LifecycleStatus::Active],
    'provisioning VPN Node' => [LifecycleStatus::Provisioning, LifecycleStatus::Active],
    'failed VPN role' => [LifecycleStatus::Active, LifecycleStatus::Failed],
    'removing VPN role' => [LifecycleStatus::Active, LifecycleStatus::Removing],
    'provisioning VPN role' => [LifecycleStatus::Active, LifecycleStatus::Provisioning],
]);

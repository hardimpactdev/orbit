<?php

declare(strict_types=1);

use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\Fleet\FleetFixtures;
use Tests\Support\NativeEnrollmentFixtures;

beforeEach(function (): void {
    $this->native = NativeEnrollmentFixtures::bind();
    $gateway = $this->markAsGateway($this->native['gateway']);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $this->input = ['name' => 'native-api-target', 'public_ssh_host' => '192.0.2.240',
        'wireguard_ip' => '10.44.0.240', 'host_key_fingerprint' => 'SHA256:'.str_repeat('A', 43)];
});

it('refuses invalid enrollment input before reservation or dispatch', function (array $changes, string $code): void {
    $this->postJson('/api/v1/nodes', [...$this->input, ...$changes])
        ->assertUnprocessable()->assertJsonPath('error.code', $code);
    expect(Node::query()->where('name', $this->input['name'])->exists())->toBeFalse()
        ->and(Operation::query()->exists())->toBeFalse()
        ->and($this->native['socket']->sent)->toBe([]);
})->with([
    [['name' => 'bad/name'], 'validation.failed'],
    [['platform' => 'windows'], 'validation.failed'],
    [['architecture' => 'arm64; invalid'], 'validation.failed'],
    [['architecture' => 'arm64'], 'node.architecture_unsupported'],
    [['architecture' => 'aarch64'], 'node.architecture_unsupported'],
    [['public_ssh_port' => 0], 'validation.failed'],
    [['public_ssh_port' => 65536], 'validation.failed'],
    [['public_ssh_port' => '22x'], 'validation.failed'],
    [['public_ssh_host' => ''], 'validation.failed'],
    [['host_key_fingerprint' => null], 'node.ssh_host_fingerprint_required'],
    [['host_key_fingerprint' => 'SHA256:short'], 'validation.failed'],
    [['user' => 'bad/name'], 'validation.failed'],
    [['orbit_user' => '1name'], 'validation.failed'],
    [['orbit_user' => 'root'], 'node.invalid_linux_user'],
    [['roles' => ['unknown-role']], 'validation.failed'],
    [['roles' => ['app-dev']], 'node.tld_required'],
    [['roles' => ['app-dev', 'app-prod'], 'tld' => 'native.test'], 'node.role_conflict'],
    [['tld' => 'not valid'], 'validation.failed'],
    [['tld' => 'orbit'], 'validation.failed'],
    [['dns_server_override' => '10.0.0.2'], 'validation.failed'],
    [['dns_server_override' => '2001:db8::53'], 'validation.failed'],
    [['dns_server_override' => '10.44.0.0'], 'validation.failed'],
    [['dns_server_override' => '10.44.0.255'], 'validation.failed'],
    [['wireguard_endpoint_override' => 'host; invalid'], 'validation.failed'],
    [['wireguard_ip' => '10.44.0.0'], 'vpn.peer_address_invalid'],
    [['wireguard_ip' => '10.44.0.255'], 'vpn.peer_address_invalid'],
    [['wireguard_ip' => '10.0.0.2'], 'vpn.peer_address_invalid'],
    [['wireguard_ip' => '2001:db8::1'], 'vpn.peer_address_invalid'],
    [['wireguard_ip' => ''], 'vpn.peer_address_invalid'],
    [['wireguard_ip' => []], 'vpn.peer_address_invalid'],
]);

it('refuses assigned peer addresses and duplicate or foreign Cluster TLDs before native work', function (): void {
    FleetFixtures::node('address-owner')->update(['wireguard_ip' => '10.44.0.240', 'tld' => 'existing.test']);
    $cluster = Cluster::query()->create(['name' => 'native-tld-owner', 'state' => ClusterState::Active, 'tld' => 'cluster.test']);
    $this->postJson('/api/v1/nodes', $this->input)->assertConflict()->assertJsonPath('error.code', 'vpn.peer_address_taken');
    $this->postJson('/api/v1/nodes', [...$this->input, 'wireguard_ip' => '10.44.0.241', 'tld' => '.EXISTING.TEST'])
        ->assertConflict()->assertJsonPath('error.code', 'node.tld_taken');
    $this->postJson('/api/v1/nodes', [...$this->input, 'wireguard_ip' => '10.44.0.241', 'tld' => $cluster->tld])
        ->assertConflict()->assertJsonPath('error.code', 'node.tld_conflict');
    expect(Operation::query()->exists())->toBeFalse()->and($this->native['socket']->sent)->toBe([]);
});

it('admits normalized candidate settings and Cluster TLD while keeping the real Node provisional', function (): void {
    $cluster = Cluster::query()->create(['name' => 'native-shared-tld', 'state' => ClusterState::Active, 'tld' => 'shared.test']);
    $response = $this->postJson('/api/v1/nodes', [...$this->input,
        'roles' => ['app-dev'], 'cluster_id' => $cluster->id, 'tld' => '.SHARED.TEST',
        'user' => 'root', 'orbit_user' => 'managed', 'lan_ip' => '192.0.2.10',
        'settings' => ['apps' => ['path' => '/srv/applications']],
    ])->assertAccepted()->assertJsonPath('data.kind', 'node.add');
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    $node = Node::query()->findOrFail($operation->target_id);
    expect($node->status)->toBe(LifecycleStatus::Provisioning)
        ->and($node->architecture)->toBeNull()->and($node->ssh_host_fingerprint)->toBeNull()
        ->and($operation->input['intent']['candidate']['tld'])->toBe('shared.test')
        ->and($operation->input['intent']['bootstrap']['target']['user'])->toBe('root')
        ->and($operation->input['intent']['candidate']['user'])->toBe('managed')
        ->and($operation->input['intent']['settings']['storage']['root'])->toBe('/srv/applications')
        ->and($node->settings)->toBeNull();
});

it('uses the recorded bootstrap endpoint and account when an existing Node omits them', function (): void {
    $node = FleetFixtures::node('native-api-existing');
    $node->update(['ssh_host_fingerprint' => 'SHA256:'.str_repeat('A', 43)]);
    $prior = $node->fresh()->getAttributes();
    $response = $this->postJson('/api/v1/nodes', ['name' => $node->name])->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    expect($operation->input['intent']['bootstrap']['target']['host'])->toBe($node->public_ssh_host)
        ->and($operation->input['intent']['bootstrap']['target']['user'])->toBe($node->user)
        ->and($node->fresh()->getAttributes())->toBe($prior);
});

it('keeps an existing Node unchanged when its requested pin or managed account conflicts', function (): void {
    $node = FleetFixtures::node('native-api-conflict', [RoleName::Database]);
    $node->update(['ssh_host_fingerprint' => 'SHA256:'.str_repeat('A', 43)]);
    $prior = $node->fresh()->getAttributes();
    $this->postJson('/api/v1/nodes', ['name' => $node->name, 'host_key_fingerprint' => 'SHA256:'.str_repeat('B', 43)])
        ->assertConflict()->assertJsonPath('error.code', 'node.ssh_host_key_mismatch');
    $this->postJson('/api/v1/nodes', ['name' => $node->name, 'orbit_user' => 'replacement'])
        ->assertConflict()->assertJsonPath('error.code', 'node.user_change_unsupported');
    expect($node->fresh()->getAttributes())->toBe($prior)
        ->and(Operation::query()->exists())->toBeFalse()->and($this->native['socket']->sent)->toBe([]);
});

it('keeps nullable field normalization while rejecting an explicit empty peer address', function (): void {
    $response = $this->postJson('/api/v1/nodes', [...$this->input, 'architecture' => '',
        'tld' => '', 'lan_ip' => '', 'wireguard_endpoint_override' => '', 'dns_server_override' => '',
    ])->assertAccepted();
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    foreach (['architecture', 'tld', 'lan_ip', 'wireguard_endpoint_override', 'dns_server_override'] as $field) {
        expect($operation->input['intent']['candidate'][$field])->toBeNull();
    }
});

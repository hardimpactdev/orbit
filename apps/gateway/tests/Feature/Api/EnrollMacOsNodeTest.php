<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use Orbit\Sdk\Requests\Nodes\AddNodeRequest;
use Tests\Support\NativeEnrollmentFixtures;

beforeEach(function (): void {
    $fixture = NativeEnrollmentFixtures::bind();
    $gateway = $this->markAsGateway($fixture['gateway']);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $this->withHeader('X-Orbit-Request-Id', fixture_request_id());
});

it('admits macOS enrollment while leaving account and host observation to the native run', function (): void {
    $this->postJson('/api/v1/nodes', [
        'name' => 'mini', 'public_ssh_host' => '192.0.2.40', 'platform' => 'macos',
        'user' => 'mini', 'orbit_user' => 'mini', 'wireguard_ip' => '10.44.0.40',
        'host_key_fingerprint' => 'SHA256:'.str_repeat('M', 43),
    ])->assertAccepted()->assertJsonPath('data.kind', 'node.add')->assertJsonPath('data.status', 'running');
    $node = Node::query()->where('name', 'mini')->sole();
    expect($node->status)->toBe(LifecycleStatus::Provisioning)
        ->and($node->architecture)->toBeNull()
        ->and($node->ssh_host_key)->toBeNull()
        ->and($node->roles()->exists())->toBeFalse();
});

it('rejects mac enrollment that omits the account, mixes accounts, or requests a role', function (): void {
    $fingerprint = 'SHA256:'.str_repeat('M', 43);
    $account = [
        'name' => 'mini',
        'public_ssh_host' => '192.0.2.40',
        'platform' => 'macos',
        'user' => 'mini',
        'orbit_user' => 'mini',
        'wireguard_ip' => '10.44.0.40',
        'host_key_fingerprint' => $fingerprint,
    ];

    $missingAccount = $this->postJson('/api/v1/nodes', [
        'name' => 'mini',
        'public_ssh_host' => '192.0.2.40',
        'platform' => 'macos',
        'wireguard_ip' => '10.44.0.40',
        'host_key_fingerprint' => $fingerprint,
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'node.macos_account_required')
        ->assertJsonPath('error.message', 'Mac enrollment requires the existing account in user and orbit_user.');
    record_fixture($missingAccount, 'nodes/node-add/macos-account-required', AddNodeRequest::class, 'POST /api/v1/nodes');

    $mismatch = $this->postJson('/api/v1/nodes', [
        ...$account,
        'orbit_user' => 'other',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'node.macos_account_mismatch')
        ->assertJsonPath('error.message', 'Mac enrollment requires the same existing account.');
    record_fixture($mismatch, 'nodes/node-add/macos-account-mismatch', AddNodeRequest::class, 'POST /api/v1/nodes');

    $role = $this->postJson('/api/v1/nodes', [
        ...$account,
        'roles' => ['app-dev'],
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'node.platform_unsupported')
        ->assertJsonPath('error.message', 'Mac Nodes cannot own service roles.');
    record_fixture($role, 'nodes/node-add/macos-role-unsupported', AddNodeRequest::class, 'POST /api/v1/nodes');

    $settings = $this->postJson('/api/v1/nodes', [
        ...$account,
        'dns_server_override' => '10.0.0.2',
    ])->assertUnprocessable()
        ->assertJsonPath('error.code', 'node.platform_unsupported')
        ->assertJsonPath('error.message', 'Mac enrollment preserves Cluster, DNS, tunnel and storage settings.');
    record_fixture($settings, 'nodes/node-add/macos-settings-unsupported', AddNodeRequest::class, 'POST /api/v1/nodes');

    $wireguard = $this->postJson('/api/v1/nodes', [
        'name' => 'mini',
        'public_ssh_host' => '192.0.2.40',
        'platform' => 'macos',
        'user' => 'mini',
        'orbit_user' => 'mini',
        'host_key_fingerprint' => $fingerprint,
    ])->assertConflict()
        ->assertJsonPath('error.code', 'node.wireguard_required')
        ->assertJsonPath('error.message', 'Mac enrollment requires its existing WireGuard address.');
    record_fixture($wireguard, 'nodes/node-add/macos-wireguard-required', AddNodeRequest::class, 'POST /api/v1/nodes');

    expect(Node::query()->where('name', 'mini')->exists())->toBeFalse();
});

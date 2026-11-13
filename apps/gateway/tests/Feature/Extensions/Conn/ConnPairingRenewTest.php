<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake([StartAdmittedSwampRun::class]);
    app(ExtensionStore::class)->set('conn', true);
    config(['swamp.run_token' => 'test-token', 'swamp.address' => 'swamp.test', 'swamp.ca_path' => '/test/ca.pem']);
    $this->node = SwampNodeFixture::ensure();
    $this->node->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->environment = ConnEnvironment::query()->create(['environment_id' => 'env-renew', 'label' => 'renew', 'url' => 'http://10.44.0.7:3773',
        'node_id' => $this->node->id, 'admin_session' => 'private-admin', 'admin_session_expires_at' => now()->addDays(20), 'registered_at' => now()]);
    // Admission copies the admin session to the Swamp vault; the socket confirms each copy.
    $frame = null;
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->andReturnUsing(function (array $sent) use (&$frame): void {
        $frame = $sent;
    });
    $socket->shouldReceive('receive')->andReturnUsing(function () use (&$frame): array {
        return [['type' => 'vault.put', 'id' => $frame['id'],
            'payload' => ['data' => ['vaultName' => $frame['payload']['vaultName'], 'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
    });
    app()->instance(WebSocketClient::class, $socket);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
});

/** @param array<string, mixed> $attributes */
function renewPairing(Node $node, ConnEnvironment $environment, array $attributes = []): ConnPairing
{
    return ConnPairing::query()->create(['node_id' => $node->id, 'conn_environment_id' => $environment->id,
        'client_label' => 'phone via Orbit '.Str::random(8), 'pairing_link_id' => 'link-'.Str::random(6), ...$attributes]);
}

/** @param array<string, mixed> $body */
function postRenewPairing(ConnEnvironment $environment, array $body): TestResponse
{
    return test()->postJson("/api/v1/conn/environments/{$environment->environment_id}/pairings", $body);
}

it('refuses a renewal when the newest pairing of the Node was revoked, and starts no Operation', function (): void {
    renewPairing($this->node, $this->environment, ['expires_at' => now()->subDays(40)]);
    renewPairing($this->node, $this->environment, ['expires_at' => now()->subDays(2), 'revoked_at' => now()->subDay()]);

    postRenewPairing($this->environment, ['renew' => true])->assertStatus(409)
        ->assertJsonPath('error.code', 'conn.pairing_revoked');

    expect(Operation::query()->count())->toBe(0)
        ->and(ConnPairing::query()->count())->toBe(2);
    Queue::assertNothingPushed();
});

it('renews when the newest pairing is active or expired but not revoked', function (array $newest): void {
    renewPairing($this->node, $this->environment, ['expires_at' => now()->subDays(40), 'revoked_at' => now()->subDays(35)]);
    renewPairing($this->node, $this->environment, $newest);

    postRenewPairing($this->environment, ['renew' => true])->assertAccepted()
        ->assertJsonPath('data.kind', 'conn.pairing_create');

    expect(Operation::query()->where('kind', 'conn.pairing_create')->count())->toBe(1)
        ->and(ConnPairing::query()->count())->toBe(3);
})->with([
    'active' => [['expires_at' => now()->addDays(3)]],
    'expired' => [['expires_at' => now()->subDay()]],
]);

it('renews when the Node has no earlier pairing on that server', function (): void {
    // Another Node's revoked pairing does not count: the rule reads the calling Node's own history.
    $other = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'tablet', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '10.44.0.78', 'wireguard_ip' => '10.44.0.78', 'user' => 'orbit']));
    renewPairing($other, $this->environment, ['revoked_at' => now()]);

    postRenewPairing($this->environment, ['renew' => true])->assertAccepted();

    expect(Operation::query()->count())->toBe(1);
});

it('pairs as before without renew, also after a revoke', function (array $body): void {
    renewPairing($this->node, $this->environment, ['revoked_at' => now()]);

    postRenewPairing($this->environment, $body)->assertAccepted();

    expect(Operation::query()->count())->toBe(1);
})->with([
    'no body' => [[]],
    'renew false' => [['renew' => false]],
]);

it('rejects a renew that is not a boolean', function (mixed $renew): void {
    postRenewPairing($this->environment, ['renew' => $renew])->assertStatus(422);

    expect(Operation::query()->count())->toBe(0);
})->with(['yes', 1, 'true', null]);

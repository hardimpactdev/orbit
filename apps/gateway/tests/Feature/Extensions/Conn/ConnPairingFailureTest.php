<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Orbit\Extensions\Conn\Domain\ConnPairingProjection;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Tests\Support\SwampNodeFixture;

uses(LazilyRefreshDatabase::class);

/** One admitted pairing Operation as IssueConnPairingAction records it. */
function connPairingOperation(): Operation
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'nick', 'status' => 'active', 'platform' => 'macos', 'architecture' => 'arm64', 'public_ssh_host' => '10.44.0.6', 'public_ssh_port' => 22,
        'wireguard_ip' => '10.44.0.6', 'user' => 'nick', 'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $environment = ConnEnvironment::query()->create(['environment_id' => 'env-beast', 'label' => 'beast', 'url' => 'http://10.44.0.7:3773',
        'admin_session' => 'private-admin', 'admin_session_expires_at' => now()->addDays(20), 'registered_at' => now()]);
    $pairing = ConnPairing::query()->create(['node_id' => $node->id, 'conn_environment_id' => $environment->id, 'client_label' => 'nick via Orbit k3j9x0qa']);

    return Operation::query()->create(['kind' => 'conn.pairing_create', 'target_type' => 'conn_pairing', 'target_id' => $pairing->id, 'node_id' => $node->id,
        'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a'], 'input' => [
            'conn_environment_id' => $environment->id, 'pairing_ids' => [$pairing->id], 'caller_node_id' => $node->id,
            'intent' => ['url' => $environment->url, 'pairing_id' => $pairing->id, 'label' => $pairing->client_label, 'request_body' => '{}'],
            'execution_secrets' => ['admin_session' => ['vault' => 'orbit-control-inputs', 'key' => 'conn-pairing-create-00000000-0000-4000-8000-000000000001']],
        ]]);
}

function failConnPairing(Operation $operation, string $detail): Operation
{
    return app(ConnPairingProjection::class)->apply($operation, [
        'run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => 'failed', 'started_at' => now()->subSeconds(4)->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => [], 'failure' => ['failed_step' => 'Store the link in vault', 'detail' => $detail, 'stderr_recorded' => false],
    ]);
}

it('keeps the code of every answer T3 gave, so callers do not retry it', function (string $code): void {
    $projected = failConnPairing(connPairingOperation(), $code);

    expect($projected->status)->toBe(OperationStatus::Failed)
        ->and($projected->error_code)->toBe($code)
        ->and($projected->result)->toBe(['error_code' => $code]);
})->with(ConnPairingProjection::AnsweredFailures);

it('reports an unknown outcome when no T3 answer was read', function (string $detail): void {
    $projected = failConnPairing(connPairingOperation(), $detail);

    expect($projected->status)->toBe(OperationStatus::Failed)
        ->and($projected->error_code)->toBe('conn.pairing_outcome_unknown');
})->with(['conn.pairing_outcome_unknown', 'Mint a pairing link timed out after 15s', 'The native step failed without stderr.']);

it('answers pairing admission after its vault copy without starting the run in the request', function (): void {
    Queue::fake([StartAdmittedSwampRun::class]);
    app(ExtensionStore::class)->set('conn', true);
    $node = SwampNodeFixture::ensure();
    $node->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $environment = ConnEnvironment::query()->create(['environment_id' => 'env-queued', 'label' => 'queued', 'url' => 'http://10.44.0.7:3773',
        'node_id' => $node->id, 'admin_session' => 'private-admin', 'admin_session_expires_at' => now()->addDays(20), 'registered_at' => now()]);
    config(['swamp.run_token' => 'test-token', 'swamp.address' => 'swamp.test', 'swamp.ca_path' => '/test/ca.pem']);
    $frame = null;
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->once()->andReturnUsing(function (array $sent) use (&$frame): void {
        expect($sent['type'])->toBe('vault.put');
        Queue::assertNothingPushed();
        $frame = $sent;
    });
    $socket->shouldReceive('receive')->once()->andReturnUsing(function () use (&$frame): array {
        return [['type' => 'vault.put', 'id' => $frame['id'],
            'payload' => ['data' => ['vaultName' => $frame['payload']['vaultName'], 'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
    });
    app()->instance(WebSocketClient::class, $socket);
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $url = "/api/v1/conn/environments/{$environment->environment_id}/pairings";
    $headers = ['Idempotency-Key' => 'queued-pairing'];

    $response = $this->postJson($url, [], $headers)->assertAccepted()->assertJsonPath('data.status', 'queued');
    $this->postJson($url, [], $headers)->assertAccepted()->assertJsonPath('data.id', $response->json('data.id'));

    expect(ConnPairing::query()->count())->toBe(1);
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);
    expect(json_encode(Operation::query()->sole()->input))->not->toContain('private-admin');
});

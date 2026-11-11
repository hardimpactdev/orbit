<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Operations\OperationStatus;
use App\Http\Mcp\ApiDispatcher;
use App\Http\Mcp\ToolManifest;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Jobs\Operations\StartAdmittedSwampRun;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Orbit\Extensions\Conn\Domain\ConnRevocationProjection;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;

uses(LazilyRefreshDatabase::class);

/** @return array{ConnPairing, Node, Node} */
function f35Pairing(): array
{
    app(ExtensionStore::class)->set('conn', true);
    $gateway = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit',
    ]));
    $gateway->roles()->create(['role' => 'gateway', 'status' => 'active']);
    $mini = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'mini', 'status' => 'active', 'platform' => 'macos', 'public_ssh_host' => '10.44.0.9', 'wireguard_ip' => '10.44.0.9', 'user' => 'nick',
    ]));
    $environment = ConnEnvironment::query()->create(['environment_id' => 'env-f35', 'label' => 'f35', 'url' => 'http://10.44.0.7:3802',
        'node_id' => $gateway->id, 'admin_session' => 'private-admin', 'admin_session_expires_at' => now()->addDays(30), 'registered_at' => now()]);
    $pairing = ConnPairing::query()->create(['node_id' => $mini->id, 'conn_environment_id' => $environment->id, 'client_label' => 'mini via Orbit p5eflnvr',
        'pairing_link_id' => '938112dd-c727-47f3-ba2d-fd15385a2973', 'issue_started_at' => '2026-11-10T16:52:41Z', 'expires_at' => '2026-11-10T16:57:43Z']);

    return [$pairing, $gateway, $mini];
}

/** @param array<string, mixed> $receipt */
function f35Project(ConnPairing $pairing, array $receipt, string $status = 'succeeded', ?string $detail = null): Operation
{
    $operation = Operation::query()->create(['kind' => 'conn.pairing_revoke', 'target_type' => 'conn_pairing_node', 'target_id' => $pairing->node_id,
        'node_id' => $pairing->environment->node_id, 'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a'],
        'input' => ['conn_environment_id' => $pairing->conn_environment_id, 'pairing_ids' => [$pairing->id], 'pairing_links' => [$pairing->pairing_link_id],
            'intent' => ['url' => $pairing->environment->url, 'labels' => [], 'links' => [$pairing->pairing_link_id], 'pairings' => [['id' => $pairing->id, 'delivered' => $pairing->credential === null]]]]]);

    return app(ConnRevocationProjection::class)->apply($operation, [
        'run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => $status, 'started_at' => now()->subSeconds(4)->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['session_receipt' => json_encode($receipt, JSON_THROW_ON_ERROR), 'link_receipt' => '{"valid":true,"completed":[0],"revoked":1}'],
        'failure' => ['detail' => $detail],
    ]);
}

it('fails a zero-session success for a delivered pairing and keeps its entire row', function (): void {
    [$pairing] = f35Pairing();
    $before = $pairing->fresh()->getRawOriginal();

    $operation = f35Project($pairing, ['valid' => true, 'completed' => [], 'revoked' => 0]);

    expect($operation->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('conn.revocation_unmatched')
        ->and($operation->result)->toBe(['error_code' => 'conn.revocation_unmatched']);
    expect($pairing->fresh()->getRawOriginal())->toBe($before);
});

it('preserves unmatched selection and reply failures without partially marking rows', function (bool $receipt): void {
    [$pairing] = f35Pairing();
    $before = $pairing->fresh()->getRawOriginal();

    $operation = f35Project($pairing, $receipt ? ['valid' => false, 'revoked' => 0, 'error_code' => 'conn.revocation_unmatched'] : [], 'failed', $receipt ? 'conn.revocation_failed' : 'conn.revocation_unmatched');

    expect($operation->error_code)->toBe('conn.revocation_unmatched');
    expect($pairing->fresh()->getRawOriginal())->toBe($before);
})->with([true, false]);

it('marks a delivered pairing only after actual session revocation', function (): void {
    [$pairing] = f35Pairing();
    $operation = f35Project($pairing, ['valid' => true, 'completed' => [0], 'revoked' => 1]);

    expect($operation->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->result)->toBe(['backend' => 'revoked', 'revoked_sessions' => 1]);
    expect($pairing->fresh()->revoked_at)->not->toBeNull();
});

it('fails an undelivered pairing revoke without a session', function (): void {
    [$pairing] = f35Pairing();
    $pairing->update(['credential' => 'not-delivered']);
    $operation = f35Project($pairing, ['valid' => true, 'completed' => [], 'revoked' => 0]);

    expect($operation->status)->toBe(OperationStatus::Failed)
        ->and($operation->error_code)->toBe('conn.revocation_unmatched');
    expect($pairing->fresh()->revoked_at)->toBeNull()
        ->and($pairing->fresh()->credential)->toBe('not-delivered');
});

it('refuses overlapping windows before dispatch even if another pairing is already marked revoked', function (): void {
    [$pairing, $gateway, $mini] = f35Pairing();
    $other = $pairing->replicate();
    $other->fill(['client_label' => 'another', 'pairing_link_id' => 'other-link', 'revoked_at' => now()])->save();
    Queue::fake([StartAdmittedSwampRun::class]);
    $before = $pairing->fresh()->getRawOriginal();

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])->postJson('/api/v1/conn/environments/env-f35/pairings/revoke', [
        'node_id' => $mini->id, 'pairing_id' => $pairing->id,
    ])->assertConflict()->assertJsonPath('error.code', 'conn.revocation_ambiguous');

    Queue::assertNothingPushed();
    expect(Operation::query()->count())->toBe(0);
    expect($pairing->fresh()->getRawOriginal())->toBe($before);
});

it('admits an explicit re-revoke through the generated MCP contract with the original window and Node IP', function (): void {
    [$pairing, $gateway, $mini] = f35Pairing();
    $pairing->update(['revoked_at' => now()]);
    Queue::fake([StartAdmittedSwampRun::class]);
    config(['swamp.run_token' => 'test-token', 'swamp.address' => 'swamp.test', 'swamp.ca_path' => '/test/ca.pem']);
    $frame = null;
    $socket = Mockery::mock(WebSocketClient::class);
    $socket->shouldReceive('connect', 'close')->andReturnNull();
    $socket->shouldReceive('isConnected')->andReturnTrue();
    $socket->shouldReceive('send')->once()->andReturnUsing(function (array $sent) use (&$frame): void {
        expect($sent['type'])->toBe('vault.put');
        $frame = $sent;
    });
    $socket->shouldReceive('receive')->once()->andReturnUsing(function () use (&$frame): array {
        return [['type' => 'vault.put', 'id' => $frame['id'], 'payload' => ['data' => [
            'vaultName' => $frame['payload']['vaultName'], 'secretKey' => $frame['payload']['key'], 'vaultType' => 'local_encryption']]]];
    });
    app()->instance(WebSocketClient::class, $socket);

    $definition = collect(ToolManifest::default()->definitions())->firstWhere('name', 'conn-pairing-revoke');
    expect($definition->inputSchema['properties']['pairing_id']['type'])->toBe('integer')
        ->and($definition->inputSchema['properties']['pairing_id']['minimum'])->toBe(1)
        ->and($definition->inputSchema['properties']['pairing_id']['description'])->toContain('already revoked');
    $caller = Request::create('/mcp', server: ['REMOTE_ADDR' => $gateway->wireguard_ip]);

    $response = app(ApiDispatcher::class)->dispatch($definition, [
        'environment' => 'env-f35', 'node_id' => $mini->id, 'pairing_id' => $pairing->id,
    ], $caller);

    expect($response->status)->toBe(202);

    $operation = Operation::query()->sole();
    expect($operation->input['pairing_ids'])->toBe([$pairing->id])
        ->and($operation->input['intent']['pairings'])->toBe([['id' => $pairing->id, 'label' => $pairing->client_label, 'ipAddress' => '10.44.0.9',
            'issuedAfter' => '2026-11-10T16:52:41.000000Z', 'expiresAt' => '2026-11-10T16:57:43.000000Z', 'delivered' => true]]);
    expect($pairing->fresh()->revoked_at)->not->toBeNull();
    Queue::assertPushed(StartAdmittedSwampRun::class, 1);
});

it('refuses an explicit pairing belonging to another Node or environment', function (string $owner): void {
    [$pairing, $gateway, $mini] = f35Pairing();
    if ($owner === 'node') {
        $pairing->update(['node_id' => $gateway->id]);
    } else {
        $other = $pairing->environment->replicate();
        $other->environment_id = 'env-other';
        $other->save();
        $pairing->update(['conn_environment_id' => $other->id]);
    }

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])->postJson('/api/v1/conn/environments/env-f35/pairings/revoke', [
        'node_id' => $mini->id, 'pairing_id' => $pairing->id,
    ])->assertNotFound();
    expect(Operation::query()->count())->toBe(0);
})->with(['node', 'environment']);

it('fails an empty admitted pairing selection instead of reporting zero-session success', function (): void {
    [$pairing] = f35Pairing();
    $operation = Operation::query()->create(['kind' => 'conn.pairing_revoke', 'target_type' => 'conn_pairing_node', 'target_id' => $pairing->node_id,
        'node_id' => $pairing->environment->node_id, 'status' => OperationStatus::Running, 'state' => ['swamp_run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a'],
        'input' => ['conn_environment_id' => $pairing->conn_environment_id, 'pairing_ids' => [], 'pairing_links' => [],
            'intent' => ['url' => $pairing->environment->url, 'labels' => [], 'links' => [], 'pairings' => []]]]);

    $projected = app(ConnRevocationProjection::class)->apply($operation, [
        'run_id' => '6b69badf-4f06-4f2e-82d7-21b62c1f587a', 'status' => 'succeeded', 'started_at' => now()->subSeconds(4)->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['session_receipt' => '{"valid":true,"completed":[],"revoked":0}', 'link_receipt' => '{"valid":true,"completed":[],"revoked":0}'],
    ]);

    expect($projected->status)->toBe(OperationStatus::Failed)
        ->and($projected->error_code)->toBe('conn.revocation_unmatched')
        ->and($projected->result)->toBe(['error_code' => 'conn.revocation_unmatched']);
    expect($pairing->fresh()->revoked_at)->toBeNull();
});

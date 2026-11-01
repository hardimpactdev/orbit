<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->caller = Node::query()->create(['name' => 'phone', 'status' => 'active', 'platform' => 'ios', 'wireguard_ip' => '10.44.0.77', 'public_ssh_host' => '10.44.0.77']);
    $this->environment = ConnEnvironment::query()->create(['environment_id' => 'direct-proof', 'label' => 'test', 'url' => 'http://t3.test', 'admin_session' => 'private-admin-sentinel', 'admin_session_expires_at' => now()->addDay(), 'registered_at' => now()]);
    $this->run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = function (array $request): array {
        if ($request['type'] === 'vault.put') {
            return [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => ['vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]];
        }

        return [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => $request['payload']['workflowIdOrName'], 'runId' => $this->run]]];
    };
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'private.shared.token', 'swamp.read_token' => 'private.read.token']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    $this->postJson('/api/v1/conn/environments/direct-proof/pairings')->assertAccepted();
    $this->operation = Operation::query()->sole();
    $this->pairing = ConnPairing::query()->sole();
    $this->publicResult = ['backend' => 'issued', 'pairing_id' => $this->pairing->id, 'pairing_link_id' => 'link-public', 'expires_at' => now()->addMinutes(5)->toIso8601String()];
    $this->credentialKey = 'nckrtl-conn-pairing-00000000-0000-4000-8000-000000000001-issue-issued-issued-credential';
    $this->nativeResult = [...$this->publicResult, 'credential_ref' => ['vault' => 'orbit-control-inputs', 'key' => $this->credentialKey]];
});

function pairingNativeTerminal(object $test, string $status = 'succeeded', bool $refuseRead = false): Operation
{
    $run = ['id' => $test->run, 'workflowName' => 'operation-conn-pairing-create', 'status' => $status,
        'inputs' => ['operation_id' => $test->operation->id, 'node_id' => $test->operation->node_id, 'kind' => 'conn.pairing_create'],
        'startedAt' => now()->toIso8601String(), 'completedAt' => now()->toIso8601String(),
        'jobs' => $status === 'succeeded' ? [['name' => 'pairing', 'steps' => [['name' => 'issue', 'outputs' => ['stdout' => json_encode($test->nativeResult)]]]]] : []];
    $test->socket->reply = static function (array $request) use ($run, $refuseRead): array {
        if ($request['type'] === 'vault.read-secret') {
            return $refuseRead ? [['type' => 'error', 'id' => $request['id'], 'error' => ['code' => 'unauthorized', 'message' => 'private-provider-diagnostic']]]
                : [['type' => 'vault.read-secret', 'id' => $request['id'], 'payload' => ['data' => ['vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['secretKey'], 'vaultType' => 'local_encryption', 'value' => 'private-one-time-credential']]]];
        }

        return [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => $run]]];
    };

    return app(SwampCommandStep::class)->projectTerminal($test->operation, $run, ['job' => 'pairing', 'step' => 'issue']);
}

it('admits full immutable public intent and copies only the existing admin session', function (): void {
    expect($this->socket->sent)->toHaveCount(2);
    expect($this->socket->sent[0]['type'])->toBe('vault.put');
    expect($this->socket->sent[0]['payload']['value'])->toBe('private-admin-sentinel');
    expect($this->socket->sent[1]['payload']['workflowIdOrName'])->toBe('operation-conn-pairing-create');
    expect($this->socket->sent[1]['payload']['inputs']['intent'])->toBe(['url' => 'http://t3.test', 'pairing_id' => $this->pairing->id, 'label' => $this->pairing->client_label]);
    expect(json_encode([$this->operation->toArray(), Activity::query()->get()->toArray()]))->not->toContain('private-admin-sentinel', '$secret');
    Http::assertNothingSent();
});

it('reads one native protected output after one history proof and stores it encrypted for one owner read', function (): void {
    pairingNativeTerminal($this);
    pairingNativeTerminal($this);
    expect($this->operation->fresh()->status->value)->toBe('succeeded');
    expect($this->operation->fresh()->result)->toBe($this->publicResult);
    expect($this->pairing->fresh()->credential)->toBe('private-one-time-credential');
    expect(DB::table('conn_pairings')->where('id', $this->pairing->id)->value('credential'))->not->toContain('private-one-time-credential');
    expect(array_count_values(array_column($this->socket->sent, 'type')))->toMatchArray(['workflow.history.get' => 1, 'vault.read-secret' => 1]);
    expect(json_encode([$this->operation->fresh()->toArray(), Activity::query()->get()->toArray()]))->not->toContain('private-one-time-credential', 'credential_ref');
    $path = '/api/v1/conn/environments/direct-proof/pairings/'.$this->pairing->id;
    $this->getJson($path)->assertOk()->assertJsonPath('data.pairing_url', 'http://t3.test/pair#token=private-one-time-credential');
    $this->getJson($path)->assertConflict();
    pairingNativeTerminal($this);
    expect($this->pairing->fresh()->credential)->toBeNull();
    Http::assertNothingSent();
});

it('keeps failed and cancelled native issues free of credential effects', function (string $status): void {
    pairingNativeTerminal($this, $status);
    expect($this->operation->fresh()->status->value)->toBe($status);
    expect($this->pairing->fresh()->credential)->toBeNull();
    expect(array_column($this->socket->sent, 'type'))->not->toContain('vault.read-secret');
})->with(['failed', 'cancelled', 'interrupted']);

it('lets shared delivery retry a secret read without issuing another pairing', function (): void {
    expect(fn () => pairingNativeTerminal($this, refuseRead: true))->toThrow(ResourceOperationException::class);
    expect($this->operation->fresh()->status->terminal())->toBeFalse();
    expect($this->pairing->fresh()->credential)->toBeNull();
    pairingNativeTerminal($this);
    expect($this->operation->fresh()->status->value)->toBe('succeeded');
    expect(array_column($this->socket->sent, 'type'))->not->toContain('model.method.run');
});

it('refuses a foreign vault reference or revoked pairing before reading any protected output', function (bool $revoked): void {
    if ($revoked) {
        $this->pairing->update(['revoked_at' => now()]);
    } else {
        $this->nativeResult['credential_ref']['key'] = 'unrelated-private-key';
    }
    expect(fn () => pairingNativeTerminal($this))->toThrow(ResourceOperationException::class);
    expect(array_column($this->socket->sent, 'type'))->not->toContain('vault.read-secret');
    expect($this->pairing->fresh()->credential)->toBeNull();
})->with([true, false]);

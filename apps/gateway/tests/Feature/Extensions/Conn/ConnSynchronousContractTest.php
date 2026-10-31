<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;

beforeEach(function (): void {
    $this->freezeTime();
    Http::preventStrayRequests();
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'conn-wait-gateway', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']));
    $this->caller = Node::query()->create(['name' => 'phone', 'status' => 'active', 'platform' => 'ios', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2']);
    $this->environment = ConnEnvironment::query()->create(['environment_id' => 'conn-wait', 'label' => 'proof', 'url' => 'http://dev.test',
        'admin_session' => 'fixture-session', 'admin_session_expires_at' => now()->addDay(), 'registered_at' => now()]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    // AwaitOperation requires the request admission to commit, as it does outside the test harness.
    while (DB::transactionLevel() > 0) {
        DB::commit();
    }
    Sleep::fake();
});

afterEach(function (): void {
    Sleep::fake(false);
    Operation::query()->where('kind', 'conn.pairing_create')->delete();
    $this->environment->delete();
    $this->caller->delete();
    $this->gateway->roles()->delete();
    $this->gateway->delete();
});

it('keeps the 201 API contract after a proven Swamp completion and consumes the credential once', function (bool $explicit): void {
    Sleep::whenFakingSleep(function (): void {
        $pairing = ConnPairing::query()->sole();
        $pairing->update(['pairing_link_id' => 'fixture-link', 'credential' => 'one-time-sentinel', 'expires_at' => now()->addMinutes(5)]);
        Operation::query()->where('target_id', $pairing->id)->update(['status' => 'succeeded', 'result' => ['pairing_id' => $pairing->id]]);
    });

    $response = $this->postJson('/api/v1/conn/environments/conn-wait/pairings', $explicit ? ['async' => false] : [])
        ->assertCreated()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.pairing_url', 'http://dev.test/pair#token=one-time-sentinel')
        ->assertJsonPath('data.pairing.node_name', 'phone');

    $operation = Operation::query()->sole();
    expect($operation->kind)->toBe('conn.pairing_create')->and($operation->input['caller_node_id'])->toBe($this->caller->id);
    expect(ConnPairing::query()->sole()->credential)->toBeNull();
    expect(json_encode(Activity::query()->get()->toArray()))->not->toContain('one-time-sentinel')->not->toContain('fixture-session');
    $this->getJson('/api/v1/conn/environments/conn-wait/pairings/'.$response->json('data.pairing.id'))->assertConflict();
    Sleep::assertSleptTimes(1);
    Http::assertNothingSent();
})->with([false, true]);

it('replays a keyed synchronous request as the existing Operation without issuing or reading twice', function (): void {
    Sleep::whenFakingSleep(function (): void {
        $pairing = ConnPairing::query()->sole();
        $pairing->update(['pairing_link_id' => 'fixture-link', 'credential' => 'one-time-sentinel', 'expires_at' => now()->addMinutes(5)]);
        Operation::query()->where('target_id', $pairing->id)->update(['status' => 'succeeded', 'result' => ['pairing_id' => $pairing->id]]);
    });

    $this->withHeader('Idempotency-Key', 'conn-sync-replay');
    $this->postJson('/api/v1/conn/environments/conn-wait/pairings')->assertCreated();
    $operation = Operation::query()->sole();
    $this->postJson('/api/v1/conn/environments/conn-wait/pairings')->assertAccepted()
        ->assertJsonPath('data.id', $operation->id)->assertJsonMissingPath('data.pairing_url');
    expect(Operation::query()->count())->toBe(1)->and(ConnPairing::query()->sole()->credential)->toBeNull();
    Sleep::assertSleptTimes(1);
    Http::assertNothingSent();
});

it('returns a 504 with the existing Operation ID when the bounded wait expires', function (): void {
    Sleep::whenFakingSleep(static function (): never {
        throw new ResourceOperationException('operation.await_timeout', 'Fixture caller deadline.', 409);
    });

    $response = $this->postJson('/api/v1/conn/environments/conn-wait/pairings')
        ->assertStatus(504)->assertJsonPath('error.code', 'conn.pairing_pending');

    $operation = Operation::query()->sole();
    expect($response->json('error.details.operation_id'))->toBe($operation->id)
        ->and($operation->status->value)->toBe('queued')->and($operation->cancel_requested_at)->toBeNull();
    Http::assertNothingSent();
});

it('reports a failed or cancelled Swamp Operation without returning a pairing URL', function (string $status, ?string $error, string $code): void {
    Sleep::whenFakingSleep(static function () use ($status, $error): void {
        Operation::query()->where('kind', 'conn.pairing_create')->update(['status' => $status, 'error_code' => $error]);
    });

    $response = $this->postJson('/api/v1/conn/environments/conn-wait/pairings')->assertStatus(502)
        ->assertJsonPath('error.code', $code)->assertJsonMissingPath('data.pairing_url');
    expect($response->json('error.details.operation_id'))->toBe(Operation::query()->sole()->id);
    Http::assertNothingSent();
})->with([['failed', 'conn.server_unreachable', 'conn.server_unreachable'], ['cancelled', null, 'conn.pairing_cancelled']]);

<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RealtimeConnection;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Nodes\NodeAccessRemovalTransactionHook;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Models\Activity;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRole;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Orbit\Extensions\Conn\Actions\Managed\EnrolConnManagedTargetAction;
use Orbit\Extensions\Conn\Domain\Managed\ConnManagedNoticePublisher;
use Orbit\Extensions\Conn\Domain\Managed\ConnManagedRevocation;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnManagedRevision;
use Orbit\Extensions\Conn\Models\ConnManagedTarget;

uses(LazilyRefreshDatabase::class);

final class RecordingManagedPublisher implements ConnManagedNoticePublisher
{
    /** @var list<array{channel: string, data: array<string, mixed>, level: int}> */
    public array $sent = [];

    public bool $reachable = true;

    public function publish(string $channel, array $data): bool
    {
        $this->sent[] = ['channel' => $channel, 'data' => $data, 'level' => DB::transactionLevel()];

        return $this->reachable;
    }
}

function managed_node(string $name, string $ip): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => $ip, 'wireguard_ip' => $ip, 'user' => 'orbit',
    ]));
}

function managed_environment(string $environmentId, Node $node): ConnEnvironment
{
    return ConnEnvironment::query()->create([
        'environment_id' => $environmentId, 'label' => 'lab', 'url' => 'http://10.44.0.30:3773', 'node_id' => $node->id,
        'admin_session' => 'admin-token', 'admin_session_expires_at' => Carbon::now()->addDays(30), 'registered_at' => Carbon::now(),
    ]);
}

/** @return array{0: ConnManagedTarget, 1: string} the target and its service credential */
function managed_enrol(string $environmentId): array
{
    $file = tempnam(sys_get_temp_dir(), 'conn-svc-');
    app(EnrolConnManagedTargetAction::class)->execute($environmentId, $file);
    $credential = (string) file_get_contents($file);
    unlink($file);

    return [ConnManagedTarget::query()->where('environment_id', $environmentId)->firstOrFail(), $credential];
}

/** @param array<string, mixed> $body */
function managed_target_call(Node $peer, string $credential, ConnManagedTarget $target, string $path, array $body = [], ?int $generation = null): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $peer->wireguard_ip])->withToken($credential)
        ->postJson('/api/v1/conn/managed/'.$path, ['environment_id' => $target->environment_id, 'generation' => $generation ?? $target->fresh()?->generation, ...$body]);
}

function managed_mint(Node $caller, string $environmentId, array $body = ['purpose' => 'reader']): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip])->postJson("/api/v1/conn/environments/{$environmentId}/managed-tokens", $body);
}

function managed_remove(Node $operator, Node $serving, Node $consumer): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $operator->wireguard_ip])->deleteJson("/api/v1/nodes/{$serving->id}/access/{$consumer->id}");
}

function managed_nonce(): string
{
    return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
}

function managed_introspect(Node $serving, string $credential, ConnManagedTarget $target, string $token, Node $caller): TestResponse
{
    return managed_target_call($serving, $credential, $target, 'introspect', ['nonce' => managed_nonce(), 'token' => $token, 'peer_address' => $caller->wireguard_ip]);
}

beforeEach(function (): void {
    app(ExtensionStore::class)->set('conn', true);
    config(['orbit.conn.managed_targets' => true]);
    $this->publisher = new RecordingManagedPublisher;
    app()->instance(ConnManagedNoticePublisher::class, $this->publisher);

    $gateway = managed_node('gateway', '10.44.0.2');
    NodeRole::withoutEvents(fn () => $gateway->roles()->create(['role' => 'gateway', 'status' => 'active']));
    $this->operator = managed_node('orbit-ops', '10.44.0.5');
    $this->operator->accessibleNodes()->attach($gateway->id);
    $this->serving = managed_node('app-dev', '10.44.0.7');
    $this->callerA = managed_node('caller-a', '10.44.0.21');
    $this->callerB = managed_node('caller-b', '10.44.0.22');
    $this->stranger = managed_node('caller-c', '10.44.0.23');
    $this->callerA->accessibleNodes()->attach($this->serving->id);
    $this->callerB->accessibleNodes()->attach($this->serving->id);
    $this->environmentId = '6f1c2e9a-3b4d-4c5e-8f60-718293a4b5c6';
    managed_environment($this->environmentId, $this->serving);
    [$this->target, $this->credential] = managed_enrol($this->environmentId);
});

describe('enrolment', function (): void {
    it('stores only the credential digest and writes a mode-0600 file', function (): void {
        $file = sys_get_temp_dir().'/conn-svc-'.bin2hex(random_bytes(4));
        $this->artisan('conn:managed-target:enrol', ['environment_id' => $this->environmentId, '--credential-file' => $file])
            ->doesntExpectOutputToContain('conn_svc_')->assertSuccessful();
        $credential = (string) file_get_contents($file);

        expect($credential)->toMatch('/^conn_svc_[A-Za-z0-9_-]{43}$/')
            ->and(fileperms($file) & 0777)->toBe(0600)
            ->and($this->target->fresh()->generation)->toBe(2)
            ->and($this->target->fresh()->credential_digest)->toBe(hash('sha256', $credential));
        unlink($file);
    });

    it('refuses an environment ID that is not a lowercase canonical UUID', function (): void {
        managed_environment('env-beast', $this->serving);

        $this->artisan('conn:managed-target:enrol', ['environment_id' => 'env-beast', '--credential-file' => sys_get_temp_dir().'/never'])
            ->expectsOutputToContain('conn.managed_environment_id_invalid')->assertFailed();
    });
});

describe('target routes', function (): void {
    it('refuses every route while the switch is off', function (): void {
        config(['orbit.conn.managed_targets' => false]);

        managed_target_call($this->serving, $this->credential, $this->target, 'authority', ['nonce' => managed_nonce(), 'after_revision' => null])
            ->assertNotFound()->assertJsonPath('error.code', 'conn.managed_disabled');
        managed_mint($this->callerA, $this->environmentId)->assertNotFound()->assertJsonPath('error.code', 'conn.managed_disabled');
        managed_mint($this->callerA, $this->environmentId, ['scopes' => ['terminal:write']])->assertNotFound()->assertJsonPath('error.code', 'conn.managed_disabled');
        managed_target_call($this->serving, $this->credential, $this->target, 'ack', [])->assertNotFound()->assertJsonPath('error.code', 'conn.managed_disabled');
    });

    it('records no Activity for target routes, which carry tokens', function (): void {
        $token = managed_mint($this->callerA, $this->environmentId)->json('data.token');
        $before = Activity::query()->count();

        managed_introspect($this->serving, $this->credential, $this->target, $token, $this->callerA)->assertOk();
        managed_target_call($this->serving, $this->credential, $this->target, 'authority', ['nonce' => managed_nonce(), 'after_revision' => null])->assertOk();

        expect(Activity::query()->count())->toBe($before)
            ->and(json_encode(Activity::query()->get()->toArray()))->not->toContain('conn_mt_')->not->toContain('conn_svc_');
    });

    it('refuses a wrong peer, a wrong credential, another target\'s credential and a wrong generation', function (): void {
        $otherId = '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';
        managed_environment($otherId, $this->serving);
        [, $otherCredential] = managed_enrol($otherId);
        $body = ['nonce' => managed_nonce(), 'after_revision' => null];

        managed_target_call($this->callerA, $this->credential, $this->target, 'authority', $body)->assertForbidden()->assertJsonPath('error.code', 'conn.managed_target_identity');
        managed_target_call($this->serving, 'conn_svc_wrong', $this->target, 'authority', $body)->assertForbidden()->assertJsonPath('error.code', 'conn.managed_target_identity');
        managed_target_call($this->serving, $otherCredential, $this->target, 'authority', $body)->assertForbidden()->assertJsonPath('error.code', 'conn.managed_target_identity');
        managed_target_call($this->serving, $this->credential, $this->target, 'authority', $body, generation: 2)->assertStatus(409)->assertJsonPath('error.code', 'conn.managed_generation_mismatch');
        managed_target_call($this->serving, $this->credential, $this->target, 'authority', $body)->assertOk()
            ->assertJsonPath('data.kind', 'snapshot')->assertJsonPath('data.nonce', $body['nonce'])->assertJsonPath('data.generation', 1);
    });

    it('signs only the current generation\'s channel', function (): void {
        managed_target_call($this->serving, $this->credential, $this->target, 'broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-orbit'])
            ->assertForbidden()->assertJsonPath('error.code', 'conn.managed_channel_forbidden');
        managed_target_call($this->serving, $this->credential, $this->target, 'broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-conn-target.'.$this->target->id.'.0'])
            ->assertForbidden();
    });

    it('signs the current generation\'s channel with the Reverb secret', function (): void {
        app()->forgetInstance(RealtimeConnection::class);
        $this->mock(WebSocketCredentialManager::class)->shouldReceive('current')->andReturn(new WebSocketCredentials('test-app', 'test-key', 'test-secret'));
        $channel = 'private-conn-target.'.$this->target->id.'.1';

        managed_target_call($this->serving, $this->credential, $this->target, 'realtime')->assertOk()->assertJsonPath('data.channel', $channel)->assertJsonPath('data.key', 'test-key');
        managed_target_call($this->serving, $this->credential, $this->target, 'broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => $channel])
            ->assertOk()->assertExactJson(['auth' => 'test-key:'.hash_hmac('sha256', '1.2:'.$channel, 'test-secret')]);
    });

    it('answers snapshot, delta, current and refuses a future revision', function (): void {
        managed_remove($this->operator, $this->serving, $this->callerA)->assertOk();
        $call = fn (?int $after): TestResponse => managed_target_call($this->serving, $this->credential, $this->target, 'authority', ['nonce' => managed_nonce(), 'after_revision' => $after]);

        expect(array_column($call(null)->assertOk()->json('data.edges'), 'consumer_node_id'))->toBe([$this->callerB->id]);
        $call(0)->assertOk()->assertJsonPath('data.kind', 'delta')->assertJsonPath('data.revisions.0.revision', 1)->assertJsonPath('data.owed.0.revision', 1);
        $call(1)->assertOk()->assertJsonPath('data.kind', 'current')->assertJsonPath('data.head_revision', 1);
        $call(2)->assertStatus(409)->assertJsonPath('error.code', 'conn.managed_revision_future');
    });
});

describe('tokens', function (): void {
    it('issues a reader token only to a direct-edge caller and refuses caller-supplied scopes', function (): void {
        $response = managed_mint($this->callerA, $this->environmentId)->assertCreated()
            ->assertJsonPath('data.scopes', ['orchestration:read', 'terminal:read'])->assertJsonPath('data.generation', 1);

        expect($response->json('data.token'))->toMatch('/^conn_mt_[A-Za-z0-9_-]{43}$/');
        managed_mint($this->operator, $this->environmentId)->assertForbidden()->assertJsonPath('error.code', 'conn.managed_direct_edge_required');
        managed_mint($this->stranger, $this->environmentId)->assertForbidden()->assertJsonPath('error.code', 'conn.managed_direct_edge_required');
        managed_mint($this->callerA, $this->environmentId, ['purpose' => 'reader', 'scopes' => ['terminal:write']])->assertUnprocessable();
        managed_mint($this->callerA, $this->environmentId, ['purpose' => 'admin'])->assertUnprocessable();
    });

    it('accepts a token only for its own target, caller and generation', function (): void {
        $token = managed_mint($this->callerA, $this->environmentId)->json('data.token');
        $otherId = '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';
        managed_environment($otherId, $this->serving);
        [$other, $otherCredential] = managed_enrol($otherId);

        managed_introspect($this->serving, $this->credential, $this->target, $token, $this->callerA)->assertOk()
            ->assertJsonPath('data.caller_node_id', $this->callerA->id)->assertJsonPath('data.revision', 0);
        managed_introspect($this->serving, $otherCredential, $other, $token, $this->callerA)->assertForbidden()->assertJsonPath('error.code', 'conn.managed_token_invalid');
        managed_introspect($this->serving, $this->credential, $this->target, $token, $this->callerB)->assertForbidden()->assertJsonPath('error.code', 'conn.managed_peer_mismatch');

        [, $rotated] = managed_enrol($this->environmentId);
        managed_introspect($this->serving, $rotated, $this->target, $token, $this->callerA)->assertForbidden()->assertJsonPath('error.code', 'conn.managed_token_invalid');
    });

    it('refuses a token after its 15 minute expiry', function (): void {
        $token = managed_mint($this->callerA, $this->environmentId)->json('data.token');

        $this->travel(15)->minutes();

        managed_introspect($this->serving, $this->credential, $this->target, $token, $this->callerA)->assertForbidden()->assertJsonPath('error.code', 'conn.managed_token_invalid');
    });

    it('issues no token against an edge that is already removed, and a re-added edge does not revive an old token', function (): void {
        $token = managed_mint($this->callerA, $this->environmentId)->json('data.token');
        $oldEdge = NodeAccess::query()->where('consumer_node_id', $this->callerA->id)->value('id');
        managed_remove($this->operator, $this->serving, $this->callerA)->assertOk();

        managed_mint($this->callerA, $this->environmentId)->assertForbidden()->assertJsonPath('error.code', 'conn.managed_direct_edge_required');

        $this->callerA->accessibleNodes()->attach($this->serving->id);
        $newEdge = NodeAccess::query()->where('consumer_node_id', $this->callerA->id)->value('id');
        expect($newEdge)->toBeGreaterThan($oldEdge);
        managed_introspect($this->serving, $this->credential, $this->target, $token, $this->callerA)->assertForbidden()->assertJsonPath('error.code', 'conn.managed_token_invalid');
        $fresh = managed_mint($this->callerA, $this->environmentId)->json('data.token');
        managed_introspect($this->serving, $this->credential, $this->target, $fresh, $this->callerA)->assertOk()->assertJsonPath('data.edge_id', $newEdge);
    });
});

describe('edge removal', function (): void {
    it('records the revision, revokes the edge\'s tokens and leaves an unrelated caller\'s token valid', function (): void {
        $tokenA = managed_mint($this->callerA, $this->environmentId)->json('data.token');
        $tokenB = managed_mint($this->callerB, $this->environmentId)->json('data.token');
        $edgeA = NodeAccess::query()->where('consumer_node_id', $this->callerA->id)->value('id');

        $this->baseLevel = DB::transactionLevel();
        managed_remove($this->operator, $this->serving, $this->callerA)->assertOk();

        $row = ConnManagedRevision::query()->sole();
        expect($row->revision)->toBe(1)->and($row->generation)->toBe(1)->and($row->edge_ids)->toBe([$edgeA])
            ->and($row->committed_at)->not->toBeNull()->and($row->notice_sent_at)->not->toBeNull()->and($row->acknowledged_at)->toBeNull()
            ->and($this->publisher->sent)->toHaveCount(1)
            ->and($this->publisher->sent[0]['channel'])->toBe('private-conn-target.'.$this->target->id.'.1')
            ->and($this->publisher->sent[0]['level'])->toBe($this->baseLevel)
            ->and($this->publisher->sent[0]['data'])->toBe(['environment_id' => $this->environmentId, 'generation' => 1, 'revision' => 1, 'edge_ids' => [$edgeA]]);
        managed_introspect($this->serving, $this->credential, $this->target, $tokenA, $this->callerA)->assertForbidden()->assertJsonPath('error.code', 'conn.managed_token_invalid');
        managed_introspect($this->serving, $this->credential, $this->target, $tokenB, $this->callerB)->assertOk()->assertJsonPath('data.revision', 1);
    });

    it('records the removal while the switch is off', function (): void {
        config(['orbit.conn.managed_targets' => false]);

        managed_remove($this->operator, $this->serving, $this->callerA)->assertOk();

        expect($this->target->fresh()->revision)->toBe(1)->and(ConnManagedRevision::query()->count())->toBe(1);
    });

    it('records and publishes nothing when the removal rolls back', function (): void {
        app()->instance('managed.failing', new class implements NodeAccessRemovalTransactionHook
        {
            public function removing(NodeAccess $edge): void
            {
                throw new RuntimeException('boom');
            }
        });
        app()->tag(['managed.failing'], NodeAccessRemovalTransactionHook::class);

        managed_remove($this->operator, $this->serving, $this->callerA)->assertServerError();

        expect(NodeAccess::query()->where('consumer_node_id', $this->callerA->id)->exists())->toBeTrue()
            ->and($this->target->fresh()->revision)->toBe(0)
            ->and(ConnManagedRevision::query()->count())->toBe(0)
            ->and($this->publisher->sent)->toBe([]);
    });

    it('suppresses the notice when a new generation replaced the captured one before the commit', function (): void {
        DB::transaction(function (): void {
            managed_remove($this->operator, $this->serving, $this->callerA)->assertOk();
            managed_enrol($this->environmentId);
        });

        $row = ConnManagedRevision::query()->sole();
        expect($this->publisher->sent)->toBe([])->and($row->notice_suppressed_at)->not->toBeNull()->and($row->notice_sent_at)->toBeNull();
    });

    it('leaves notice_sent_at empty when Reverb is unreachable, and the owed row stays for the probe', function (): void {
        $this->publisher->reachable = false;

        managed_remove($this->operator, $this->serving, $this->callerA)->assertOk();

        expect(ConnManagedRevision::query()->sole()->notice_sent_at)->toBeNull();
        managed_target_call($this->serving, $this->credential, $this->target, 'authority', ['nonce' => managed_nonce(), 'after_revision' => 0])
            ->assertOk()->assertJsonPath('data.owed.0.revision', 1);
    });

    it('wires the managed revocation as a transaction hook', function (): void {
        expect(collect(app()->tagged(NodeAccessRemovalTransactionHook::class))->map(fn (object $hook): string => $hook::class)->all())
            ->toContain(ConnManagedRevocation::class);
    });
});

describe('acknowledgement', function (): void {
    beforeEach(function (): void {
        managed_remove($this->operator, $this->serving, $this->callerA)->assertOk();
        $this->observed = ['revision' => 1, 'kind' => 'observed', 'notice_received_at' => '2026-11-18T15:00:00.100000+00:00',
            'fenced_at' => '2026-11-18T15:00:00.101000+00:00', 'handlers_closed_at' => '2026-11-18T15:00:00.102000+00:00', 'recovered_at' => null];
    });

    it('settles the exact revision once and answers a retry with the first result', function (): void {
        $first = managed_target_call($this->serving, $this->credential, $this->target, 'ack', $this->observed)->assertOk()
            ->assertJsonPath('data.revision', 1)->assertJsonPath('data.kind', 'observed')->json('data');
        $retry = managed_target_call($this->serving, $this->credential, $this->target, 'ack', [...$this->observed, 'fenced_at' => '2026-11-18T15:00:09.000000+00:00'])->assertOk()->json('data');

        expect($retry)->toBe($first)->and(ConnManagedRevision::query()->sole()->fenced_at)->toBe('2026-11-18T15:00:00.101000+00:00');
        managed_target_call($this->serving, $this->credential, $this->target, 'authority', ['nonce' => managed_nonce(), 'after_revision' => 1])->assertJsonPath('data.owed', []);
    });

    it('keeps a row owed when no ACK arrived and refuses an unknown or future revision', function (): void {
        managed_target_call($this->serving, $this->credential, $this->target, 'ack', [...$this->observed, 'revision' => 2])
            ->assertStatus(409)->assertJsonPath('error.code', 'conn.managed_ack_unknown');

        expect(ConnManagedRevision::query()->sole()->acknowledged_at)->toBeNull();
    });

    it('refuses an ACK whose kind and times disagree', function (): void {
        managed_target_call($this->serving, $this->credential, $this->target, 'ack', [...$this->observed, 'kind' => 'recovered'])->assertUnprocessable();
    });

    it('refuses an ACK of a superseded generation and lists the new generation\'s owed rows only', function (): void {
        [, $rotated] = managed_enrol($this->environmentId);

        managed_target_call($this->serving, $rotated, $this->target, 'ack', $this->observed)
            ->assertStatus(409)->assertJsonPath('error.code', 'conn.managed_generation_mismatch');
        managed_target_call($this->serving, $rotated, $this->target, 'authority', ['nonce' => managed_nonce(), 'after_revision' => null])
            ->assertOk()->assertJsonPath('data.generation', 2)->assertJsonPath('data.owed', []);
        expect(ConnManagedRevision::query()->sole())->superseded_at->not->toBeNull()->acknowledged_at->toBeNull();
    });

    it('takes a recovered ACK after a restart', function (): void {
        managed_target_call($this->serving, $this->credential, $this->target, 'ack', [
            'revision' => 1, 'kind' => 'recovered', 'notice_received_at' => null, 'fenced_at' => null, 'handlers_closed_at' => null,
            'recovered_at' => '2026-11-18T15:00:05.000000+00:00',
        ])->assertOk()->assertJsonPath('data.kind', 'recovered');

        expect(ConnManagedRevision::query()->sole()->recovered_at)->toBe('2026-11-18T15:00:05.000000+00:00');
    });
});

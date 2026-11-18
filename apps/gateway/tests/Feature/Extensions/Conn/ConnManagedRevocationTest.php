<?php

declare(strict_types=1);

use App\Actions\Nodes\RemoveNodeAccessAction;
use App\Domain\Broadcasting\RealtimeConnection;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Nodes\NodeAccessRemovalHook;
use App\Domain\Nodes\NodeAccessRemovalTransactionHook;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Models\Activity;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\NodeRole;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Orbit\Extensions\Conn\Actions\Managed\EnrolConnManagedTargetAction;
use Orbit\Extensions\Conn\Actions\Managed\IssueConnManagedTokenAction;
use Orbit\Extensions\Conn\Domain\Managed\ConnManagedAuthority;
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

/** Copies this test's schema and the rows mint reads into a SQLite file that two connections can share. */
function managed_overlap_database(): string
{
    $path = sys_get_temp_dir().'/conn-overlap-'.bin2hex(random_bytes(6)).'.sqlite';
    $file = new PDO('sqlite:'.$path);
    $file->exec('PRAGMA foreign_keys = OFF');
    $objects = DB::select("SELECT type, sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%' ORDER BY CASE type WHEN 'table' THEN 0 WHEN 'index' THEN 1 ELSE 2 END");
    foreach ($objects as $object) {
        $file->exec($object->sql);
    }
    foreach (['nodes', 'node_access', 'conn_environments', 'conn_managed_targets'] as $table) {
        foreach (DB::table($table)->get() as $row) {
            $row = (array) $row;
            $file->prepare(sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', array_keys($row)), implode(', ', array_fill(0, count($row), '?'))))
                ->execute(array_values($row));
        }
    }

    return $path;
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

    it('keeps the old generation when the credential file cannot be delivered', function (): void {
        managed_remove($this->operator, $this->serving, $this->callerA)->assertOk();
        $directory = sys_get_temp_dir().'/conn-svc-dir-'.bin2hex(random_bytes(4));
        mkdir($directory);

        expect(fn () => app(EnrolConnManagedTargetAction::class)->execute($this->environmentId, $directory))->toThrow(ResourceOperationException::class);
        $this->artisan('conn:managed-target:enrol', ['environment_id' => $this->environmentId, '--credential-file' => $directory.'/missing/svc'])->assertFailed();

        expect($this->target->fresh())->generation->toBe(1)->credential_digest->toBe(hash('sha256', $this->credential))
            ->and(ConnManagedRevision::query()->sole()->superseded_at)->toBeNull()
            ->and(glob($directory.'*.tmp'))->toBe([])->and(glob($directory.'/missing*'))->toBe([]);
        rmdir($directory);
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

    it('answers conn.managed_disabled before the peer and extension checks', function (): void {
        config(['orbit.conn.managed_targets' => false]);
        $unknown = (new Node)->forceFill(['wireguard_ip' => '10.44.9.9']);

        managed_target_call($unknown, $this->credential, $this->target, 'authority', ['nonce' => managed_nonce(), 'after_revision' => null])
            ->assertNotFound()->assertJsonPath('error.code', 'conn.managed_disabled');
        managed_mint($unknown, $this->environmentId)->assertNotFound()->assertJsonPath('error.code', 'conn.managed_disabled');
        app(ExtensionStore::class)->set('conn', false);
        managed_mint($this->callerA, $this->environmentId)->assertNotFound()->assertJsonPath('error.code', 'conn.managed_disabled');
        managed_target_call($this->serving, $this->credential, $this->target, 'introspect', [])->assertNotFound()->assertJsonPath('error.code', 'conn.managed_disabled');
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

    it('reads head, revisions and owed rows in one transaction', function (): void {
        managed_remove($this->operator, $this->serving, $this->callerA)->assertOk();
        $base = DB::transactionLevel();
        $levels = [];
        DB::listen(function (QueryExecuted $query) use (&$levels): void {
            if (str_contains($query->sql, 'conn_managed_revisions') || str_contains($query->sql, 'conn_managed_targets')) {
                $levels[] = DB::transactionLevel();
            }
        });

        app(ConnManagedAuthority::class)->state($this->target, managed_nonce(), 0);

        expect($levels)->not->toBeEmpty()->and(array_unique($levels))->toBe([$base + 1]);
    });

    it('never reads or settles a successor generation for a request authenticated before rotation', function (): void {
        $authenticated = $this->target->fresh();
        [, $rotated] = managed_enrol($this->environmentId);
        managed_remove($this->operator, $this->serving, $this->callerA)->assertOk();
        $authority = app(ConnManagedAuthority::class);
        $ack = ['revision' => 1, 'kind' => 'observed', 'notice_received_at' => null, 'fenced_at' => '2026-11-18T15:00:00.1+00:00', 'handlers_closed_at' => '2026-11-18T15:00:00.2+00:00', 'recovered_at' => null];

        expect(fn () => $authority->acknowledge($authenticated, $ack))->toThrow(ResourceOperationException::class, 'The generation is not the current one.')
            ->and(fn () => $authority->state($authenticated, managed_nonce(), null))->toThrow(ResourceOperationException::class, 'The generation is not the current one.')
            ->and(ConnManagedRevision::query()->sole())->generation->toBe(2)->acknowledged_at->toBeNull();
        $token = managed_mint($this->callerB, $this->environmentId)->json('data.token');
        expect(fn () => $authority->introspect($authenticated, managed_nonce(), $token, $this->callerB->wireguard_ip))
            ->toThrow(ResourceOperationException::class, 'The generation is not the current one.');
        managed_introspect($this->serving, $rotated, $this->target, $token, $this->callerB)->assertOk()->assertJsonPath('data.generation', 2);
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
        managed_mint($this->callerA, $this->environmentId, ['purpose' => 'reader', 'scopes' => []])->assertUnprocessable()->assertJsonValidationErrors('scopes', 'error.details');
        managed_mint($this->callerA, $this->environmentId, ['purpose' => 'reader', 'scopes' => null])->assertUnprocessable();
    });

    it('advertises the same expiry that introspection enforces', function (): void {
        $minted = managed_mint($this->callerA, $this->environmentId)->json('data');

        managed_introspect($this->serving, $this->credential, $this->target, $minted['token'], $this->callerA)->assertOk()
            ->assertJsonPath('data.expires_at', $minted['expires_at']);
        $this->travelTo(Carbon::parse($minted['expires_at'])->subMicrosecond());
        managed_introspect($this->serving, $this->credential, $this->target, $minted['token'], $this->callerA)->assertOk();
    });

    it('accepts a token only for its own target, caller and generation', function (): void {
        $token = managed_mint($this->callerA, $this->environmentId)->json('data.token');
        $otherId = '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';
        managed_environment($otherId, $this->serving);
        [$other, $otherCredential] = managed_enrol($otherId);

        $nonce = managed_nonce();
        managed_target_call($this->serving, $this->credential, $this->target, 'introspect', ['nonce' => $nonce, 'token' => $token, 'peer_address' => $this->callerA->wireguard_ip])->assertOk()
            ->assertJsonPath('data.nonce', $nonce)->assertJsonPath('data.environment_id', $this->environmentId)->assertJsonPath('data.generation', 1)
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

describe('overlapping writers', function (): void {
    it('issues no token while a removal of the edge holds the write lock, and refuses once it commits', function (): void {
        $edge = NodeAccess::query()->where('consumer_node_id', $this->callerA->id)->value('id');
        $path = managed_overlap_database();
        config(['database.connections.overlap' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => false,
            'busy_timeout' => 200, 'transaction_mode' => 'IMMEDIATE']]);
        $remover = new PDO('sqlite:'.$path);
        $remover->exec('BEGIN IMMEDIATE');
        $remover->exec('DELETE FROM node_access WHERE id = '.$edge);
        $default = config('database.default');
        config(['database.default' => 'overlap']);

        try {
            expect(fn () => app(IssueConnManagedTokenAction::class)->execute($this->callerA, $this->environmentId))->toThrow(PDOException::class, 'locked');
            $remover->exec('COMMIT');
            expect(fn () => app(IssueConnManagedTokenAction::class)->execute($this->callerA, $this->environmentId))
                ->toThrow(ResourceOperationException::class, 'The caller has no direct edge to the serving Node.')
                ->and(DB::connection('overlap')->table('conn_managed_tokens')->count())->toBe(0);
        } finally {
            config(['database.default' => $default]);
            DB::purge('overlap');
            unset($remover);
            @unlink($path);
        }
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
            ->and($row->commit_observed_at >= $row->committed_at)->toBeTrue()->and($row->notice_sent_at >= $row->commit_observed_at)->toBeTrue()
            ->and($this->publisher->sent)->toHaveCount(1)
            ->and($this->publisher->sent[0]['channel'])->toBe('private-conn-target.'.$this->target->id.'.1')
            ->and($this->publisher->sent[0]['level'])->toBe($this->baseLevel + 1)
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
            public function edgeDeleted(NodeAccess $edge): void
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

    it('runs the legacy removal hooks only after the outermost commit', function (): void {
        $calls = new ArrayObject;
        app()->instance('managed.legacy-spy', new class($calls) implements NodeAccessRemovalHook
        {
            public function __construct(private ArrayObject $calls) {}

            public function removed(Node $consumer, Node $serving): void
            {
                $this->calls->append(DB::transactionLevel());
            }
        });
        app()->tag(['managed.legacy-spy'], NodeAccessRemovalHook::class);
        $base = DB::transactionLevel();

        DB::transaction(function () use ($calls): void {
            app(RemoveNodeAccessAction::class)->execute($this->callerA, $this->serving, $this->operator);
            expect($calls->count())->toBe(0)->and(ConnManagedRevision::query()->count())->toBe(1);
        });

        expect($calls->getArrayCopy())->toBe([$base]);
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
        $retry = managed_target_call($this->serving, $this->credential, $this->target, 'ack', [...$this->observed, 'handlers_closed_at' => '2026-11-18T15:00:09.000000+00:00'])->assertOk()->json('data');

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

    it('leaves the revision owed when the ACK evidence is not valid', function (array $change): void {
        managed_target_call($this->serving, $this->credential, $this->target, 'ack', [...$this->observed, ...$change])->assertUnprocessable();

        expect(ConnManagedRevision::query()->sole()->acknowledged_at)->toBeNull();
    })->with([
        'numeric fence' => [['fenced_at' => 1763478000]],
        'numeric close' => [['handlers_closed_at' => 1763478000.5]],
        'time without an offset' => [['fenced_at' => '2026-11-18 15:00:00']],
        'close before fence' => [['handlers_closed_at' => '2026-11-18T15:00:00.100500+00:00']],
    ]);

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

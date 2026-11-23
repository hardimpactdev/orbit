<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\MachineBus;
use App\Domain\Engine\StepSpec;
use App\Domain\Executor\ExecutorOrderSigner;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Instances\ContainerModelRoute;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\InstanceCreateEngineFlow;
use App\Domain\Instances\InstanceFinalize;
use App\Domain\Instances\InstanceFinalizeEngineFlow;
use App\Domain\Instances\InstanceFleetOperation;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\OperationEngineStep;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Orbit\Extensions\Conn\Actions\IssueConnPairingAction;
use Orbit\Extensions\Conn\Domain\ConnAgentActivity;
use Orbit\Extensions\Conn\Domain\ConnPairingProjection;
use Orbit\Extensions\Conn\Domain\ConnReservations;
use Orbit\Extensions\Conn\Jobs\MintConnReservationPairing;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Orbit\Extensions\Conn\Models\ConnProfile;
use Orbit\Extensions\Conn\Models\ConnProfileBinding;
use Orbit\Extensions\Conn\Models\ConnReservation;

/*
 * Reserved clones (docs/reference/conn.md#reserve-a-clone): a device reserves a clone when its user opens a new thread, and gives it its
 * name when the user submits. Its completion follows the finalize; an unused reservation expires and is destroyed.
 */

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/../../Domain/Instances/ContainerInstanceHelpers.php';

beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $swamp = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'control', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
    ]));
    $swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    app(ExtensionStore::class)->set('conn', true);
    app()->instance(ContainerModelRoute::class, new class implements ContainerModelRoute
    {
        public function assertReady(): void {}

        public function origin(): string
        {
            return 'http://10.44.0.3:8318';
        }

        public function key(): string
        {
            return 'placeholder-model-key';
        }
    });
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('root-certificate');
    $this->vault = swampVaultStandIn();
    $this->reader = workspaceReader();
    $this->beast = containerDefaultNode();
    $this->phone = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'phone', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.77', 'public_ssh_host' => '10.44.0.77', 'user' => 'orbit',
    ]));
    $this->phone->accessibleNodes()->attach($this->beast->id);
});

/** @param  array<string, mixed>  $body */
function reservationCall(Node $caller, string $method, string $path, array $body = []): TestResponse
{
    // An empty body is the JSON object `{}`, as a device sends it; a finalize takes the request's Idempotency-Key.
    $server = ['REMOTE_ADDR' => $caller->wireguard_ip, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ...(str_ends_with($path, '/finalize') ? ['HTTP_IDEMPOTENCY_KEY' => (string) Str::uuid()] : [])];

    return test()->call($method, '/api/v1/conn/'.$path, [], [], [], $server, $body === [] ? '{}' : json_encode($body, JSON_THROW_ON_ERROR));
}

/** A node-package Project's seeded container default on the Node: no database and no Route, so its clones can be reserved. */
function reservationDefault(Node $node, string $slug = 'pkg'): Instance
{
    $project = Project::query()->create(['name' => ucfirst($slug), 'slug' => $slug, 'type' => ProjectType::NodePackage,
        'repository_url' => "https://github.com/nckrtl/{$slug}.git", 'default_branch' => 'main', 'root' => '.', 'is_laravel' => false, 'source_facts_captured_at' => now()]);
    $default = Instance::query()->findOrFail(containerCreate($project, $node)->target_id);
    $default->update(['status' => InstanceState::Active, 'first_setup_pending' => false]);
    InstanceRuntime::query()->where('instance_id', $default->id)->firstOrFail()
        ->update(['seed_commit' => str_repeat('c', 40), 'power_state' => InstanceRuntimePowerState::Running]);

    return $default->refresh();
}

function reserve(Node $caller, Instance $default, int $status = 202): ConnReservation
{
    $response = reservationCall($caller, 'POST', "projects/{$default->project_id}/reservations")->assertStatus($status);

    return ConnReservation::query()->findOrFail($response->json('data.id'));
}

/** The reserved clone's create ends as a succeeded engine create does. */
function reservationCreated(ConnReservation $reservation): Operation
{
    $create = Operation::query()->findOrFail($reservation->create_operation_id);
    $create->forceFill(['status' => 'running', 'state' => [...($create->state ?? []), 'swamp_run_id' => 'run-1']])->save();
    app(DevelopmentInstanceCreateProjection::class)->apply($create->refresh(), ['status' => 'succeeded', 'run_id' => 'run-1',
        'started_at' => now()->subSeconds(6)->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['ready' => true, 'instance_id' => $create->target_id, 'commit' => str_repeat('c', 40), 't3' => [
            'environment_id' => 'env-'.$create->target_id, 'server_version' => '0.1.10', 'expires_at' => '2026-12-10T10:00:00.000Z',
            'gateway_session' => ['vault' => 'orbit-control-inputs', 'key' => 'nckrtl-instance-t3-model-register-session-gateway-1-token'],
            'swamp_session' => ['vault' => 'orbit-control-inputs', 'key' => 'nckrtl-instance-t3-model-register-session-swamp-1-token']]]]);

    return $create->refresh();
}

it('reserves a clone under a draft name whose completion waits, and returns the open one again', function (): void {
    $default = reservationDefault($this->beast);

    $first = reserve($this->phone, $default);
    $this->travel(3)->minutes();
    $again = reserve($this->phone, $default, 200);

    $create = Operation::query()->findOrFail($first->create_operation_id);
    $instance = Instance::query()->findOrFail($first->instance_id);
    expect($again->id)->toBe($first->id)
        ->and($instance->name)->toStartWith('draft-')
        ->and($create->input['completion_deferred'])->toBeTrue()
        ->and($again->expires_at->getTimestamp())->toBe(now()->addMinutes(ConnReservations::LifetimeMinutes)->getTimestamp())
        ->and(reservationCall($this->phone, 'GET', "reservations/{$first->id}")->assertOk()->json('data'))
        ->toMatchArray(['state' => 'preparing', 'instance_id' => $instance->id, 'environment_id' => null, 'create_operation_id' => $create->id]);

    // Ready: the device pairs with the server now, and hides it from its threads until the finalize.
    reservationCreated($first);
    expect(reservationCall($this->phone, 'GET', "reservations/{$first->id}")->json('data'))->toMatchArray(['state' => 'ready', 'environment_id' => 'env-'.$instance->id])
        ->and(Operation::query()->where('kind', 'instance.complete')->exists())->toBeFalse()
        ->and(collect(reservationCall($this->phone, 'GET', 'environments')->json('data'))->firstWhere('instance_id', $instance->id)['reservation_id'])->toBe($first->id);
});

it('keeps at most three open reservations per device: a fourth releases the oldest and destroys its clone', function (): void {
    $reservations = array_map(fn (string $slug): ConnReservation => reserve($this->phone, reservationDefault($this->beast, $slug)), ['one', 'two', 'three', 'four']);

    $oldest = $reservations[0]->refresh();
    expect($oldest->state)->toBe(ConnReservation::Released)
        ->and(Operation::query()->findOrFail($oldest->destroy_operation_id)->kind)->toBe('instance.destroy')
        ->and(ConnReservation::query()->where('state', ConnReservation::Open)->count())->toBe(3);
});

it('refuses a Project whose clones take a database, a Valkey user or a Route by their name, and leaves nothing behind', function (array $plan, string $reason): void {
    $operation = (new Operation)->forceFill(['input' => ['direct_provisioning' => $plan]]);

    expect(fn () => (new ReflectionMethod(ConnReservations::class, 'assertSupported'))->invoke(null, $operation))
        ->toThrow(fn (ResourceOperationException $error) => expect([$error->errorCode, $error->details])->toBe(['reservation.unsupported', ['reason' => $reason]]));
})->with([
    'a database' => [['database' => ['kind' => 'sqlite']], 'database'],
    'a server database' => [['server_database' => ['slug' => 'pg']], 'database'],
    'a Valkey user' => [['server_valkey' => ['slug' => 'valkey']], 'valkey'],
    'a Route' => [['route' => ['route_id' => 4]], 'route'],
]);

it('finalizes a ready reservation: the name at once under the Instance lock, then the branch, names and label, then the completion', function (): void {
    $default = reservationDefault($this->beast);
    $reservation = reserve($this->phone, $default);
    $draft = Instance::query()->findOrFail($reservation->instance_id)->name;
    reservationCall($this->phone, 'POST', "reservations/{$reservation->id}/finalize", ['name' => 'fix-login'])->assertStatus(409)->assertJsonPath('error.code', 'reservation.not_ready');
    reservationCreated($reservation);

    $response = reservationCall($this->phone, 'POST', "reservations/{$reservation->id}/finalize", ['name' => 'fix-login'])->assertAccepted();

    $finalize = Operation::query()->findOrFail($response->json('data.id'));
    $instance = Instance::query()->findOrFail($reservation->instance_id);
    expect([$finalize->kind, $finalize->lock_key, $instance->name, $instance->branch_override])->toBe([InstanceFinalize::Kind, OperationStore::lockKey('instance', $instance->id), 'fix-login', null])
        ->and($finalize->input['branch'])->toBe(['mode' => 'rename', 'from' => $draft, 'to' => 'fix-login'])
        ->and($finalize->input['machine_name']['pretty'])->toContain('fix-login')
        ->and($finalize->input['instance_file'])->toContain("ORBIT_INSTANCE=fix-login\n")
        ->and($finalize->input['label'])->toBe('pkg / fix-login')
        ->and(reservationCall($this->phone, 'GET', "reservations/{$reservation->id}")->json('data.state'))->toBe('finalizing')
        // The device follows its finalize through Conn's own Operation read; it is not cancelled there.
        ->and(reservationCall($this->phone, 'GET', "operations/{$finalize->id}")->assertOk()->json('data.kind'))->toBe(InstanceFinalize::Kind)
        ->and(reservationCall($this->phone, 'POST', "operations/{$finalize->id}/cancel")->status())->toBe(404)
        // A second finalize names the first.
        ->and(reservationCall($this->phone, 'POST', "reservations/{$reservation->id}/finalize", ['name' => 'other'])->assertStatus(409)->json('error.code'))->toBe('reservation.finalized');

    $finalize->forceFill(['status' => 'running'])->save();
    app(InstanceFinalize::class)->close($finalize->refresh(), ['branch' => 'fix-login', 'commit' => str_repeat('c', 40), 'restart_ms' => 900], null, null);

    $completion = Operation::query()->findOrFail($finalize->refresh()->result['completion_operation_id']);
    expect([$finalize->status, $finalize->lock_key, $completion->kind, $completion->input['name']])->toBe([OperationStatus::Succeeded, null, 'instance.complete', 'fix-login'])
        ->and(ConnEnvironment::query()->where('instance_id', $instance->id)->value('label'))->toBe('pkg / fix-login')
        ->and($instance->refresh()->branch)->toBe('fix-login')
        ->and(reservationCall($this->phone, 'GET', "reservations/{$reservation->id}")->json('data.state'))->toBe('finalized');
});

it('refuses a finalize under a name another Instance holds, and keeps the reservation', function (): void {
    $default = reservationDefault($this->beast);
    $reservation = reserve($this->phone, $default);
    reservationCreated($reservation);
    $taken = Instance::query()->findOrFail($reservation->instance_id)->replicate()->fill(['name' => 'taken', 'checkout_path' => '/tmp/other']);
    $taken->save();

    reservationCall($this->phone, 'POST', "reservations/{$reservation->id}/finalize", ['name' => 'taken'])->assertStatus(409)->assertJsonPath('error.code', 'instance.name_taken');

    expect($reservation->refresh()->state)->toBe(ConnReservation::Open);
});

it('expires an unused reservation and destroys its clone, which cancels its open fleet wait', function (): void {
    $reservation = reserve($this->phone, reservationDefault($this->beast));
    reservationCreated($reservation);
    $fleet = app(OperationStore::class)->create(InstanceFleetOperation::Kind, 'instance', $reservation->instance_id, $this->beast->id, ['instance_id' => $reservation->instance_id], null, null);

    $this->travel(ConnReservations::LifetimeMinutes + 1)->minutes();
    app(ConnReservations::class)->sweep();

    $reservation->refresh();
    expect($reservation->state)->toBe(ConnReservation::Expired)
        ->and(Operation::query()->findOrFail($reservation->destroy_operation_id)->kind)->toBe('instance.destroy')
        ->and($fleet->refresh()->cancel_requested_at)->not->toBeNull()
        ->and(reservationCall($this->phone, 'POST', "reservations/{$reservation->id}/finalize", ['name' => 'late'])->assertStatus(410)->json('error.code'))->toBe('reservation.expired');
});

it('never destroys a finalized clone: the expiry after a finalize, even past the lifetime, finds it finalized', function (): void {
    $reservation = reserve($this->phone, reservationDefault($this->beast));
    reservationCreated($reservation);
    reservationCall($this->phone, 'POST', "reservations/{$reservation->id}/finalize", ['name' => 'kept'])->assertAccepted();

    $this->travel(ConnReservations::LifetimeMinutes + 1)->minutes();
    app(ConnReservations::class)->sweep();
    $released = app(ConnReservations::class)->release($reservation, ConnReservation::Expired);

    expect($released)->toBeNull()
        ->and($reservation->refresh()->state)->toBe(ConnReservation::Finalized)
        ->and(Operation::query()->where('kind', 'instance.destroy')->exists())->toBeFalse();
});

it('keeps a reserved clone awake while its user types, and lets it sleep by the usual rules after its finalize or expiry', function (): void {
    $reservation = reserve($this->phone, reservationDefault($this->beast));
    $instance = Instance::query()->findOrFail($reservation->instance_id);

    expect(app(ConnAgentActivity::class)->read($instance)?->held)->toBe('conn_reservation');

    $this->travel(ConnReservations::LifetimeMinutes + 1)->minutes();
    expect(ConnReservations::holds($instance))->toBeFalse();
});

it('serves a reservation only to the device that made it', function (): void {
    $reservation = reserve($this->phone, reservationDefault($this->beast));
    $other = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'laptop', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.78', 'public_ssh_host' => '10.44.0.78', 'user' => 'orbit',
    ]));
    $other->accessibleNodes()->attach($this->beast->id);

    reservationCall($other, 'GET', "reservations/{$reservation->id}")->assertNotFound();
    reservationCall($other, 'DELETE', "reservations/{$reservation->id}")->assertNotFound();
    reservationCall($other, 'POST', "reservations/{$reservation->id}/finalize", ['name' => 'mine'])->assertNotFound();

    expect(reservationCall($this->phone, 'DELETE', "reservations/{$reservation->id}")->assertAccepted()->json('data.state'))->toBe('released');
});

it('runs a finalize as a branch rename and the names, or a fetch of the selected branch and a switch to it', function (): void {
    $reservation = reserve($this->phone, reservationDefault($this->beast));
    reservationCreated($reservation);
    $finalize = Operation::query()->findOrFail(reservationCall($this->phone, 'POST', "reservations/{$reservation->id}/finalize", ['name' => 'fix-login'])->json('data.id'));
    $flow = app(InstanceFinalizeEngineFlow::class);
    $run = new EngineRun($finalize->id, [], 0, null);

    $names = $flow->input($finalize, 'names', $run);
    expect(array_map(static fn (StepSpec $step): array => [$step->name, $step->action, $step->target], $flow->steps($finalize)))
        ->toBe([['names', 'instance.names', (string) $reservation->instance_id]])
        ->and($names)->toMatchArray(['instance_id' => $reservation->instance_id, 'workspace' => '/srv/workspace', 't3_unit' => 't3.service', 't3_port' => 3773,
            'label_seconds' => InstanceFinalize::LabelSeconds])
        ->and($names['branch']['mode'])->toBe('rename');

    // A selection: the fetch brings the branch into the Node's mirror, and the names switch the workspace to it at the fetched commit.
    $switch = $finalize->replicate()->forceFill(['input' => [...$finalize->input,
        'branch' => ['mode' => 'switch', 'selection' => ['mode' => 'new', 'target' => 'fix-login', 'base' => 'release', 'source_sha' => str_repeat('b', 40)]]]]);
    $fetched = new EngineRun($finalize->id, ['fetch' => (new OperationEngineStep)->forceFill(['status' => 'done', 'node_id' => $this->beast->id,
        'output' => json_encode(['commit' => str_repeat('d', 40)])])], 0, null);
    expect(array_map(static fn (StepSpec $step): array => [$step->name, $step->after], $flow->steps($switch)))->toBe([['fetch', []], ['names', ['fetch']]])
        ->and($flow->input($switch, 'names', $fetched)['branch'])->toBe(['mode' => 'switch', 'branch' => 'fix-login', 'fetched' => 'release',
            'mirror' => $finalize->input['container']['mirror'], 'expected_commit' => str_repeat('d', 40)]);
});

it('ends a finalize past its budget on the reconciler\'s pass and lets go of the Instance lock', function (): void {
    $reservation = reserve($this->phone, reservationDefault($this->beast));
    reservationCreated($reservation);
    $finalize = Operation::query()->findOrFail(reservationCall($this->phone, 'POST', "reservations/{$reservation->id}/finalize", ['name' => 'slow'])->json('data.id'));
    $finalize->forceFill(['status' => 'running', 'started_at' => now(), 'state' => [...($finalize->state ?? []), 'swamp_run_id' => 'engine-'.$finalize->id]])->save();

    $this->travel(421)->seconds();
    app(InstanceFinalize::class)->sweep();

    expect([$finalize->refresh()->status, $finalize->error_code, $finalize->lock_key])->toBe([OperationStatus::Failed, 'operation.timed_out', null])
        ->and(reservationCall($this->phone, 'GET', "reservations/{$reservation->id}")->json('data'))->toMatchArray(['state' => 'failed', 'error_code' => 'operation.timed_out'])
        ->and(Operation::query()->where('kind', 'instance.complete')->exists())->toBeFalse();
});

it('mints a one-time pairing in the background once ready, delivers it on GET with no-store, and caps expiry to the reservation', function (): void {
    $gateway = Node::query()->where('name', 'control')->firstOrFail();
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $profile = ConnProfile::query()->create(['name' => 'Nick', 'settings' => ['workspaces' => []]]);
    ConnProfileBinding::query()->create(['node_id' => $this->phone->id, 'conn_profile_id' => $profile->id]);

    $reservation = reserve($this->phone, reservationDefault($this->beast));
    expect($reservation->conn_profile_id)->toBe($profile->id)
        ->and(reservationCall($this->phone, 'GET', "reservations/{$reservation->id}")->assertOk()->json('data.pairing'))->toBeNull();

    reservationCreated($reservation);
    $environment = ConnEnvironment::query()->where('instance_id', $reservation->instance_id)->firstOrFail();
    Queue::assertPushed(MintConnReservationPairing::class, fn (MintConnReservationPairing $job): bool => $job->reservationId === $reservation->id
        && $job->environmentId === $environment->id);

    // Before the background mint completes, ready answers pairing: null so the client can fall back.
    expect(reservationCall($this->phone, 'GET', "reservations/{$reservation->id}")->assertOk()->json('data'))
        ->toMatchArray(['state' => 'ready', 'environment_id' => $environment->environment_id, 'pairing' => null]);

    // Run the background job to admit conn.pairing_create and project its minted link (with a longer T3 TTL than the reservation).
    (new MintConnReservationPairing($reservation->id, $environment->id))->handle(app(ConnReservations::class));
    $reservation->refresh();
    expect($reservation->conn_pairing_id)->not->toBeNull();
    $pairing = ConnPairing::query()->findOrFail($reservation->conn_pairing_id);
    $pairingOp = Operation::query()->where('kind', 'conn.pairing_create')->where('target_id', $pairing->id)->firstOrFail();
    $pairingOp->forceFill(['status' => 'running', 'state' => [...($pairingOp->state ?? []), 'swamp_run_id' => 'pairing-run-1']])->save();
    $vaultKey = 'nckrtl-conn-pairing-11111111-2222-4333-8444-555555555555-issue-issued-pairing-'.$pairing->id.'-credential';
    app(ConnPairingProjection::class)->apply($pairingOp->refresh(), [
        'status' => 'succeeded', 'run_id' => 'pairing-run-1', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['issued_receipt' => json_encode([
            'backend' => 'issued', 'pairing_id' => $pairing->id, 'pairing_link_id' => 'link-1',
            'expires_at' => now()->addMinutes(30)->toIso8601String(),
            'credential_ref' => ['vault' => 'orbit-control-inputs', 'key' => $vaultKey],
        ], JSON_THROW_ON_ERROR)],
    ]);

    // First GET after mint delivers the IssuedConnPairingData once with Cache-Control: no-store and expires_at capped to the reservation.
    $first = reservationCall($this->phone, 'GET', "reservations/{$reservation->id}")->assertOk();
    expect($first->headers->get('Cache-Control'))->toContain('no-store')
        ->and($first->json('data.pairing.pairing_url'))->toBe(rtrim($environment->url, '/').'/pair#token=gateway-session')
        ->and($first->json('data.pairing.pairing'))->toMatchArray([
            'id' => $pairing->id, 'environment_id' => $environment->environment_id, 'node_id' => $this->phone->id,
            'node_name' => $this->phone->name, 'client_label' => $pairing->client_label, 'revoked_at' => null,
        ])
        ->and($first->json('data.pairing.pairing.expires_at'))->toBe($first->json('data.expires_at'))
        ->and($pairing->refresh()->credential)->toBeNull();

    // Second GET returns pairing: null (one-time delivery).
    expect(reservationCall($this->phone, 'GET', "reservations/{$reservation->id}")->assertOk()->json('data.pairing'))->toBeNull();
});

it('binds a reservation and its pairing to both the reserving device and its profile, and clears unread credentials on expiry or release', function (): void {
    $gateway = Node::query()->where('name', 'control')->firstOrFail();
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $profileA = ConnProfile::query()->create(['name' => 'Alpha', 'settings' => ['workspaces' => []]]);
    $profileB = ConnProfile::query()->create(['name' => 'Beta', 'settings' => ['workspaces' => []]]);
    ConnProfileBinding::query()->create(['node_id' => $this->phone->id, 'conn_profile_id' => $profileA->id]);

    $reservation = reserve($this->phone, reservationDefault($this->beast));
    reservationCreated($reservation);
    $environment = ConnEnvironment::query()->where('instance_id', $reservation->instance_id)->firstOrFail();

    // Switching profile before the background mint skips minting and hides the reservation from the new profile.
    ConnProfileBinding::query()->where('node_id', $this->phone->id)->update(['conn_profile_id' => $profileB->id]);
    (new MintConnReservationPairing($reservation->id, $environment->id))->handle(app(ConnReservations::class));
    expect($reservation->refresh()->conn_pairing_id)->toBeNull();
    reservationCall($this->phone, 'GET', "reservations/{$reservation->id}")->assertNotFound();
    reservationCall($this->phone, 'POST', "reservations/{$reservation->id}/finalize", ['name' => 'switched'])->assertNotFound();

    // Switch back to the reserving profile, mint, and verify an expired reservation clears the unread credential and returns pairing: null.
    ConnProfileBinding::query()->where('node_id', $this->phone->id)->update(['conn_profile_id' => $profileA->id]);
    (new MintConnReservationPairing($reservation->id, $environment->id))->handle(app(ConnReservations::class));
    $pairing = ConnPairing::query()->findOrFail($reservation->refresh()->conn_pairing_id);
    $pairing->update(['credential' => 'unread-secret', 'expires_at' => now()->addMinutes(20)]);

    $this->travel(ConnReservations::LifetimeMinutes + 1)->minutes();
    expect(reservationCall($this->phone, 'GET', "reservations/{$reservation->id}")->assertOk()->json('data'))
        ->toMatchArray(['state' => 'expired', 'pairing' => null])
        ->and($pairing->refresh()->credential)->toBeNull();
});

/**
 * The reserved clone's create ends on the engine: its `t3` step's output sealed to the Gateway, with T3's answer to the pairing link
 * when the step asked for one.
 *
 * @param  array<string, mixed>|null  $pairing
 */
function reservationEngineCreated(ConnReservation $reservation, ?array $pairing): Operation
{
    $create = Operation::query()->findOrFail($reservation->create_operation_id);
    $create->forceFill(['status' => 'running', 'state' => [...($create->state ?? []), 'swamp_run_id' => EngineRun::runIdOf($create->id)]])->save();
    $sealed = ['environment' => json_encode(['environmentId' => 'env-'.$create->target_id, 'serverVersion' => '0.4.1'], JSON_THROW_ON_ERROR),
        'gateway' => ['token' => 'gateway-session', 'status' => 200, 'body' => json_encode(['expiresAt' => '2026-12-10T10:00:00.000Z'], JSON_THROW_ON_ERROR)],
        ...($pairing === null ? [] : ['pairing' => $pairing])];
    $box = base64_encode(sodium_crypto_box_seal(json_encode($sealed, JSON_THROW_ON_ERROR), (string) base64_decode(ExecutorOrderSigner::fromConfig()->sealRecipient(), true)));
    $step = static fn (array $output): OperationEngineStep => (new OperationEngineStep)->forceFill(['status' => 'done', 'node_id' => $create->node_id,
        'output' => json_encode($output, JSON_THROW_ON_ERROR)]);
    app(InstanceCreateEngineFlow::class)->finish($create->refresh(), new EngineRun($create->id, [
        'checkout' => $step(['commit' => str_repeat('c', 40)]),
        't3' => $step(['environment_id' => 'env-'.$create->target_id, 'at_boot' => false, 'sealed' => $box]),
    ], (int) now()->subSeconds(6)->getTimestampMs(), (int) now()->getTimestampMs()));

    return $create->refresh();
}

it('has a reserved clone\'s create issue its device\'s pairing link, so the first read of ready hands it out and nothing mints in the background', function (): void {
    // The engine seals the `t3` step's output to the Gateway's executor key.
    config(['orbit.executor.signing_key' => base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))]);
    $profile = ConnProfile::query()->create(['name' => 'Nick', 'settings' => ['workspaces' => []]]);
    ConnProfileBinding::query()->create(['node_id' => $this->phone->id, 'conn_profile_id' => $profile->id]);
    $reservation = reserve($this->phone, reservationDefault($this->beast));
    $create = Operation::query()->findOrFail($reservation->create_operation_id);
    $label = 'phone via Orbit '.substr(str_replace('-', '', $reservation->id), 0, 8);
    $bus = Mockery::mock(MachineBus::class);
    $bus->shouldReceive('nodeFeatures')->with($this->beast->id)->andReturn(['instance.t3_pairing']);
    app()->instance(MachineBus::class, $bus);

    expect(app(InstanceCreateEngineFlow::class)->input($create, 't3', new EngineRun($create->id, [], 0, null))['pairing'])
        ->toBe(['label' => $label, 'scopes' => IssueConnPairingAction::Scopes]);

    $expires = now()->addMinutes(30)->toIso8601ZuluString();
    $create = reservationEngineCreated($reservation, ['status' => 201,
        'body' => json_encode(['id' => 'link-1', 'credential' => 'create-link', 'expiresAt' => $expires, 'label' => $label], JSON_THROW_ON_ERROR)]);

    $environment = ConnEnvironment::query()->where('instance_id', $reservation->instance_id)->firstOrFail();
    $pairing = ConnPairing::query()->findOrFail($reservation->refresh()->conn_pairing_id);
    expect(json_encode($create->result, JSON_THROW_ON_ERROR))->not->toContain('create-link')
        ->and($pairing)->toMatchArray(['node_id' => $this->phone->id, 'conn_environment_id' => $environment->id, 'pairing_link_id' => 'link-1', 'client_label' => $label]);
    Queue::assertNotPushed(MintConnReservationPairing::class);

    $first = reservationCall($this->phone, 'GET', "reservations/{$reservation->id}")->assertOk();
    expect($first->json('data.state'))->toBe('ready')
        ->and($first->json('data.pairing.pairing_url'))->toBe(rtrim($environment->url, '/').'/pair#token=create-link')
        ->and($first->json('data.pairing.pairing.expires_at'))->toBe($first->json('data.expires_at'))
        ->and($pairing->refresh()->credential)->toBeNull()
        ->and(reservationCall($this->phone, 'GET', "reservations/{$reservation->id}")->assertOk()->json('data.pairing'))->toBeNull();
});

it('mints the link in the background when the create brought none or one T3 refused, and asks no executor that does not take it', function (array $features, ?array $answer): void {
    // The engine seals the `t3` step's output to the Gateway's executor key.
    config(['orbit.executor.signing_key' => base64_encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair()))]);
    $reservation = reserve($this->phone, reservationDefault($this->beast));
    $create = Operation::query()->findOrFail($reservation->create_operation_id);
    $bus = Mockery::mock(MachineBus::class);
    $bus->shouldReceive('nodeFeatures')->andReturn($features);
    app()->instance(MachineBus::class, $bus);

    expect(array_key_exists('pairing', app(InstanceCreateEngineFlow::class)->input($create, 't3', new EngineRun($create->id, [], 0, null))))
        ->toBe($features !== []);
    reservationEngineCreated($reservation, $answer);

    expect($reservation->refresh()->conn_pairing_id)->toBeNull();
    Queue::assertPushed(MintConnReservationPairing::class, fn (MintConnReservationPairing $job): bool => $job->reservationId === $reservation->id);
})->with([
    'an executor without the input' => [[], null],
    'T3 unreachable' => [['instance.t3_pairing'], ['status' => 0, 'body' => '']],
    'T3 refused' => [['instance.t3_pairing'], ['status' => 401, 'body' => '{"error":"unauthorized"}']],
    'another label' => [['instance.t3_pairing'], ['status' => 201, 'body' => '{"id":"link-1","credential":"c","expiresAt":"2026-11-22T07:00:00Z","label":"someone else"}']],
]);

it('asks no pairing link of a create that is no open reservation\'s', function (): void {
    $reservation = reserve($this->phone, reservationDefault($this->beast));
    $create = Operation::query()->findOrFail($reservation->create_operation_id);
    app(ConnReservations::class)->release($reservation, ConnReservation::Released);

    expect(app(ConnReservations::class)->createPairing($create))->toBeNull();
});

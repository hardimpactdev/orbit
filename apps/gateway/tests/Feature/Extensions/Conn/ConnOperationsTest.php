<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\Http;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    // Use the real immutable registry once across this test's HTTP requests.
    $this->app->instance(OperationKinds::class, $this->app->make(OperationKinds::class));
    $this->freezeTime();
    Http::preventStrayRequests();
    $this->gateway = $this->markAsGateway(Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1', 'user' => 'orbit']));
    $this->services = Node::query()->create(['name' => 'services', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.17', 'wireguard_ip' => '10.44.0.17', 'user' => 'orbit']);
    $this->services->accessibleNodes()->attach($this->gateway);
    config(['dependency_detection.services_node_id' => $this->services->id]);
    $this->caller = Node::query()->create(['name' => 'phone', 'status' => 'active', 'platform' => 'ios', 'public_ssh_host' => '10.44.0.77', 'wireguard_ip' => '10.44.0.77']);
    $this->other = Node::query()->create(['name' => 'laptop', 'status' => 'active', 'platform' => 'macos', 'public_ssh_host' => '10.44.0.78', 'wireguard_ip' => '10.44.0.78']);
    $this->environment = ConnEnvironment::query()->create(['environment_id' => 'conn-h-proof', 'label' => 'dev', 'node_id' => $this->services->id,
        'url' => 'http://dev.test', 'admin_session' => 'fixture-admin-session', 'admin_session_expires_at' => now()->addDay(), 'registered_at' => now()]);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-conn-direct', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'fixture.shared.token']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    $this->postJson('/api/v1/conn/environments/conn-h-proof/pairings', ['async' => true])->assertAccepted();
    $this->operation = Operation::query()->sole();
    $this->pairing = ConnPairing::query()->sole();

});

it('refuses expired sessions and missing environments before admitting another issue', function (): void {
    $this->environment->update(['admin_session_expires_at' => now()->subMinute()]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);

    $this->postJson('/api/v1/conn/environments/conn-h-proof/pairings', ['async' => true])->assertConflict()->assertJsonPath('error.code', 'conn.session_expired');
    $this->postJson('/api/v1/conn/environments/missing/pairings')->assertNotFound();

    expect(Operation::query()->count())->toBe(1)->and(ConnPairing::query()->count())->toBe(1);
    Http::assertNothingSent();
});

it('lets the no-grant originating caller read and cancel only its own Conn Operation', function (): void {
    $path = '/api/v1/conn/operations/'.$this->operation->id;
    $this->withServerVariables(['REMOTE_ADDR' => $this->other->wireguard_ip]);
    $this->getJson($path)->assertNotFound();
    $this->call('POST', $path.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertNotFound();
    expect($this->operation->fresh()->cancel_requested_at)->toBeNull();
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    $this->getJson('/api/v1/operations/'.$this->operation->id)->assertForbidden();

    $this->getJson($path)->assertOk()->assertJsonPath('data.id', $this->operation->id)->assertJsonPath('data.kind', 'conn.pairing_create');
    $this->call('POST', $path.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertOk();

    expect($this->operation->fresh()->cancel_requested_at)->not->toBeNull()->and($this->caller->accessibleNodes()->count())->toBe(0);
    Http::assertNothingSent();
});

it('keeps the revocation requester separate from its subject for owner access and attribution', function (): void {
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    $this->postJson('/api/v1/conn/environments/conn-h-proof/pairings/revoke', ['node_id' => $this->other->id])->assertAccepted();
    $operation = Operation::query()->where('kind', 'conn.pairing_revoke')->sole();
    expect($operation->target_id)->toBe($this->other->id)->and($operation->callerNodeId())->toBe($this->caller->id)
        ->and($operation->input['subject_node_id'])->toBe($this->other->id);

    $this->getJson('/api/v1/conn/operations/'.$operation->id)->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => $this->other->wireguard_ip]);
    $this->getJson('/api/v1/conn/operations/'.$operation->id)->assertNotFound();
    $this->call('POST', '/api/v1/conn/operations/'.$operation->id.'/cancel', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{}')->assertNotFound();
    Http::assertNothingSent();
});

it('keeps a credential unreadable before completion and clears it when expired', function (): void {
    $this->pairing->update(['credential' => 'unread-sentinel', 'pairing_link_id' => 'link', 'expires_at' => now()->addMinute()]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
    $url = '/api/v1/conn/environments/conn-h-proof/pairings/'.$this->pairing->id;

    $this->getJson($url)->assertConflict();

    expect($this->pairing->fresh()->credential)->toBe('unread-sentinel');
    $this->operation->update(['status' => 'succeeded']);
    $this->travel(2)->minutes();
    $this->getJson($url)->assertConflict();
    expect($this->pairing->fresh()->credential)->toBeNull();
});

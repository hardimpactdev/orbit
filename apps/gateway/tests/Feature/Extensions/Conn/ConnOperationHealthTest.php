<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\Http;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

beforeEach(function (): void {
    $this->freezeTime();
    Http::preventStrayRequests();
    app(ExtensionStore::class)->set('conn', true);
    $this->services = $this->markAsGateway(Node::query()->create(['name' => 'services', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1', 'user' => 'orbit']));
    config(['dependency_detection.services_node_id' => $this->services->id]);
    $this->caller = Node::query()->create(['name' => 'phone', 'status' => 'active', 'platform' => 'ios',
        'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2']);
    $this->other = Node::query()->create(['name' => 'other-phone', 'status' => 'active', 'platform' => 'ios',
        'public_ssh_host' => '192.0.2.3', 'wireguard_ip' => '10.44.0.3']);
    ConnEnvironment::query()->create(['environment_id' => 'conn-health', 'label' => 'dev', 'node_id' => $this->services->id,
        'url' => 'http://dev.test', 'admin_session' => 'health-session-sentinel', 'admin_session_expires_at' => now()->addDay(), 'registered_at' => now()]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->caller->wireguard_ip]);
});

it('keeps an unclaimed revocation private to its original requester', function (): void {
    $this->postJson('/api/v1/conn/environments/conn-health/pairings/revoke')->assertAccepted();
    $operation = Operation::query()->sole();
    $this->travel(6)->minutes();
    $this->getJson('/api/v1/conn/operations/'.$operation->id)->assertOk()->assertJsonPath('data.assistance.error_code', 'operation.executor_unclaimed');
    $this->withServerVariables(['REMOTE_ADDR' => $this->other->wireguard_ip]);

    $this->getJson('/api/v1/conn/operations/'.$operation->id)->assertNotFound();

    Http::assertNothingSent();
});

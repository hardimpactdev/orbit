<?php

declare(strict_types=1);

use App\Actions\Routes\AdmitRouteOperationAction;
use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\NativeRouteRemovalAdmission;
use App\Domain\Routes\NativeRouteRemovalProjection;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('public-root');
    $this->manifest = tempnam(sys_get_temp_dir(), 'removal-agent-');
    file_put_contents($this->manifest, json_encode(['commit' => str_repeat('b', 40), 'version' => '0.5.0', 'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64), 'url' => 'https://example.test/agent']]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($this->manifest, sys_get_temp_dir()));
    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    });
    $this->node = Node::query()->create(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-pin', 'name' => 'removal', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
    $this->control = Node::query()->create(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-pin', 'name' => 'control', 'public_ssh_host' => '192.0.2.14', 'wireguard_ip' => '10.44.0.14', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
    $this->control->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->node->accessibleNodes()->attach($this->control);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Removal', 'slug' => 'removal', 'repository_url' => 'https://example.test/removal.git', 'root' => 'public']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'dev', 'checkout_path' => '/home/orbit/removal', 'status' => 'reserved']);
    $this->route = Route::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'domain' => 'removal.private.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $this->route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $this->socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $this->socket);
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    config(['dependency_detection.services_node_id' => $this->control->id, 'swamp.run_token' => 'fixture.fake']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
});

afterEach(function (): void {
    unlink($this->manifest);
});

it('admits complete withdrawal before dispatch and keeps targets until the native root succeeds', function (): void {
    $this->deleteJson('/api/v1/routes/'.$this->route->id)->assertAccepted();
    $operation = Operation::query()->sole();
    expect($this->socket->sent)->toHaveCount(1)
        ->and($this->socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-route-removal')
        ->and($operation->state)->not->toHaveKey('route_plan')
        ->and($operation->input['route_removal']['routes'][0]['route_id'])->toBe($this->route->id)
        ->and($this->route->fresh()->status)->toBe(RouteStatus::Pending)
        ->and($this->route->targets()->count())->toBe(1);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->getJson($path.'?run_id='.$this->runId)->assertForbidden()->assertJsonPath('error.code', 'operation.executor_forbidden');
    expect(app(NativeRouteRemovalProjection::class)->input($operation)['intent'])->toMatchArray(['route_id' => $this->route->id, 'removal' => true]);
    Event::fake([RecordBroadcast::class]);
    $terminal = ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-31T08:00:00.000Z', 'finished_at' => '2026-10-31T08:00:01.000Z', 'result' => ['ready' => true, 'retained_on_nodes' => []]];
    $this->postJson($path.'/result', $terminal)->assertForbidden()->assertJsonPath('error.code', 'operation.executor_forbidden');
    expect($this->route->targets()->count())->toBe(1)->and($operation->fresh()->status)->toBe(OperationStatus::Running);
    app(NativeRouteRemovalProjection::class)->apply($operation, $terminal);
    app(NativeRouteRemovalProjection::class)->apply($operation, $terminal);
    expect($operation->fresh()->status)->toBe(OperationStatus::Succeeded);
    expect(Event::dispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::RouteDeleted))->toHaveCount(1);
    Event::assertDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::RouteDeleted && $event->id === $this->route->id);
    $this->assertDatabaseMissing('routes', ['id' => $this->route->id]);
    $this->assertDatabaseHas('instances', ['id' => $this->instance->id]);
});

it('keeps rows and associations when a native withdrawal fails', function (): void {
    $operation = app(AdmitRouteOperationAction::class)->destroy($this->route);
    app(NativeRouteRemovalProjection::class)->apply($operation, ['run_id' => $this->runId, 'status' => 'failed', 'started_at' => '2026-10-31T08:00:00.000Z', 'finished_at' => '2026-10-31T08:00:01.000Z', 'error_code' => 'route.withdrawal_failed', 'result' => []]);
    expect($this->route->fresh()->status)->toBe(RouteStatus::Pending)
        ->and($this->route->targets()->count())->toBe(1)
        ->and($operation->fresh()->status)->toBe(OperationStatus::Failed);
});

it('projects withdrawal without deleting the owning Route', function (): void {
    $operation = app(NativeRouteRemovalAdmission::class)->start('route.withdraw', $this->route);
    app(NativeRouteRemovalProjection::class)->apply($operation, ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-31T08:00:00.000Z', 'finished_at' => '2026-10-31T08:00:01.000Z', 'result' => ['ready' => true]]);
    expect($this->route->fresh()->status)->toBe(RouteStatus::Retiring)
        ->and($this->route->targets()->count())->toBe(1);
});

it('keeps complete immutable inputs on the coordinator and denies a foreign participant callback', function (): void {
    $operation = app(NativeRouteRemovalAdmission::class)->start('route.destroy', $this->route, true);
    expect($operation->node_id)->toBe($this->control->id);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->postJson($path.'/result', ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-31T08:00:00.000Z', 'finished_at' => '2026-10-31T08:00:01.000Z', 'result' => ['ready' => true]])->assertForbidden();
    $this->getJson($path.'/private-material?run_id='.$this->runId)->assertForbidden();
    expect($operation->input)->not->toHaveKeys(['environment_fields', 'private_bindings'])
        ->and($this->route->targets()->count())->toBe(1);
});

it('captures removal URL material in named execution copies before starting the root', function (): void {
    $this->route->update(['web_root' => 'docs/public']);
    $this->node->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-pin']);
    $this->instance->update(['selected_php_version' => '8.5']);
    $survivor = Route::query()->create(['project_id' => $this->instance->project_id, 'node_id' => $this->node->id,
        'domain' => 'survivor.private.test', 'web_root' => 'docs/public', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $survivor->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $survivor->update(['status' => 'active', 'sites_published' => true]);
    $this->socket->reply = static fn (array $request): array => $request['type'] === 'vault.put'
        ? [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => ['vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]]
        : [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => $request['payload']['workflowIdOrName'], 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];

    $operation = app(NativeRouteRemovalAdmission::class)->start('route.destroy', $this->route);

    $write = $operation->input['route_lifecycle']['route_mutations']['forward']['url_writes'][0];
    expect($write['input']['url'])->toBe('https://survivor.private.test')
        ->and($write['input'])->not->toHaveKeys(['app_key', 'app_name'])
        ->and($operation->input['execution_secrets'][$write['secret_field']])->toHaveKeys(['vault', 'key']);
    $requests = $this->socket->sent;
    expect($requests[0]['type'])->toBe('vault.put')
        ->and($requests[0]['payload']['value'])->toContain('APP_KEY=')
        ->and(end($requests)['type'])->toBe('workflow.run')
        ->and(end($requests)['payload']['inputs']['intent'])->toBe($operation->input['route_lifecycle'])
        ->and(json_encode(end($requests), JSON_THROW_ON_ERROR))->not->toContain('APP_KEY=');
});

it('fails withdrawal and starts no native root when the execution copy is not acknowledged', function (): void {
    $this->route->update(['web_root' => 'docs/public']);
    $this->node->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-pin']);
    $this->instance->update(['selected_php_version' => '8.5']);
    $survivor = Route::query()->create(['project_id' => $this->instance->project_id, 'node_id' => $this->node->id,
        'domain' => 'survivor.private.test', 'web_root' => 'docs/public', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $survivor->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $survivor->update(['status' => 'active', 'sites_published' => true]);
    $this->socket->reply = static fn (): array => [];

    $this->deleteJson('/api/v1/routes/'.$this->route->id)->assertServiceUnavailable()
        ->assertJsonPath('error.code', 'swamp.vault_sync_unavailable');

    expect(Operation::query()->sole())->status->toBe(OperationStatus::Failed)->error_code->toBe('swamp.vault_sync_unavailable')
        ->and(array_column($this->socket->sent, 'type'))->toBe(['vault.put']);
});

it('keeps captured rows when cancellation is recorded and rejects a later conflicting success', function (): void {
    $operation = app(AdmitRouteOperationAction::class)->destroy($this->route);
    $result = ['run_id' => $this->runId, 'status' => 'cancelled', 'started_at' => '2026-10-31T08:00:00.000Z', 'finished_at' => '2026-10-31T08:00:01.000Z', 'result' => []];
    app(NativeRouteRemovalProjection::class)->apply($operation, $result);
    expect(fn () => app(NativeRouteRemovalProjection::class)->apply($operation, [...$result, 'status' => 'succeeded', 'result' => ['ready' => true]]))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('operation.completion_conflict'));
    expect($operation->fresh()->status)->toBe(OperationStatus::Cancelled)
        ->and($this->route->targets()->count())->toBe(1);
});

it('keeps the admitted target immutable when an Instance later moves', function (): void {
    $operation = app(AdmitRouteOperationAction::class)->destroy($this->route);
    $before = $operation->input;
    $this->instance->update(['node_id' => $this->control->id]);
    $input = app(NativeRouteRemovalProjection::class)->input($operation->fresh());
    expect($input['intent'])->toBe($before['route_lifecycle'])
        ->and($operation->fresh()->status)->toBe(OperationStatus::Running)
        ->and($this->route->targets()->count())->toBe(1);
});

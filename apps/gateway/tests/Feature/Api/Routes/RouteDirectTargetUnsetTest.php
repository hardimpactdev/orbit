<?php

declare(strict_types=1);

use App\Actions\Routes\AdmitRouteOperationAction;
use App\Actions\Routes\RouteTargetState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->node = Node::query()->create(['name' => 'unset', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active']);
    $this->control = Node::query()->create(['name' => 'control', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active']);
    $this->control->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->node->accessibleNodes()->attach($this->control);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Unset', 'slug' => 'unset', 'repository_url' => 'https://example.test/unset.git']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'dev', 'checkout_path' => '/home/orbit/unset', 'status' => 'reserved']);
    $this->route = Route::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'domain' => 'unset.private.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $this->route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $this->socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $this->socket);
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    config(['dependency_detection.services_node_id' => $this->control->id, 'swamp.run_token' => 'fixture.fake', 'swamp.direct_writers' => []]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
});

it('starts unset directly and confirms the stored empty targets on its own Node', function (): void {
    $this->deleteJson('/api/v1/routes/'.$this->route->id.'/target')->assertAccepted();
    $operation = Operation::query()->sole();
    expect($this->route->fresh()->targets)->toBeEmpty();
    expect($operation->state)->toHaveKey('route_target_unset_writer', 'direct')->not->toHaveKey('route_plan');
    expect($operation->input['node_ids'])->toBe([$this->node->id]);
    expect($operation->worker)->toBeNull();
    expect($operation->children()->exists())->toBeFalse();
    expect($this->socket->sent)->toHaveCount(1);
    expect($this->socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-route-target-unset');
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->getJson($path.'?run_id='.$this->runId)->assertOk()
        ->assertJsonPath('data.input', ['route_id' => $this->route->id, 'target_ids' => []]);
    $this->postJson($path.'/result', ['run_id' => $this->runId, 'status' => 'succeeded',
        'started_at' => '2026-10-31T04:30:00.000Z', 'finished_at' => '2026-10-31T04:30:01.000Z', 'result' => ['ready' => true]])->assertOk();
    expect($operation->fresh()->status->value)->toBe('succeeded');
});

it('refuses the removed polled writer without detaching the target', function (): void {
    config(['swamp.direct_writers' => ['route.target_unset' => 'polled']]);
    $this->deleteJson('/api/v1/routes/'.$this->route->id.'/target')->assertServiceUnavailable()
        ->assertJsonPath('error.code', 'operation.writer_invalid');
    expect($this->route->targets()->pluck('instance_id')->all())->toBe([$this->instance->id]);
    expect(Operation::query()->count())->toBe(0);
    expect($this->socket->sent)->toBe([]);
});

it('preserves active targets and refuses ownership drift', function (): void {
    $this->route->update(['status' => 'active']);
    $this->instance->update(['status' => 'active']);
    $this->deleteJson('/api/v1/routes/'.$this->route->id.'/target')->assertConflict()->assertJsonPath('error.code', 'route.target_conflict');
    expect(fn () => app(RouteTargetState::class)->clear($this->route, []))->toThrow(ResourceOperationException::class, 'owner changed');
    expect($this->route->targets()->pluck('instance_id')->all())->toBe([$this->instance->id]);
    expect(Operation::query()->count())->toBe(0);
});

it('refuses successful projection when a target reappears', function (): void {
    $operation = app(AdmitRouteOperationAction::class)->clearTarget($this->route);
    $this->route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->getJson($path.'?run_id='.$this->runId)->assertConflict()->assertJsonPath('error.code', 'route.target_conflict');
    $this->postJson($path.'/result', ['run_id' => $this->runId, 'status' => 'succeeded',
        'started_at' => '2026-10-31T04:30:00.000Z', 'finished_at' => '2026-10-31T04:30:01.000Z', 'result' => ['ready' => true]])->assertConflict();
    expect($operation->fresh()->result)->toBeNull();
});

it('binds the native root before the first successful worker read', function (): void {
    $readBeforeBinding = false;
    $this->socket->reply = function (array $request) use (&$readBeforeBinding): array {
        $operation = Operation::query()->sole();
        $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$this->runId)
            ->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');
        $readBeforeBinding = true;

        return [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started',
            'workflowName' => $request['payload']['workflowIdOrName'], 'runId' => $this->runId]]];
    };

    $operation = app(AdmitRouteOperationAction::class)->clearTarget($this->route);
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$this->runId)->assertOk();
    expect($readBeforeBinding)->toBeTrue();
    expect($operation->fresh()->state['swamp_run_id'])->toBe($this->runId);
});

<?php

declare(strict_types=1);

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
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
});

it('clears the target set as a record-only write without a run or an Operation', function (): void {
    $this->deleteJson('/api/v1/routes/'.$this->route->id.'/target')->assertOk()
        ->assertJsonPath('data.id', $this->route->id)->assertJsonPath('data.targets', []);
    expect($this->route->fresh()->targets)->toBeEmpty()
        ->and(Operation::query()->count())->toBe(0)
        ->and($this->socket->sent)->toBe([]);
    $this->deleteJson('/api/v1/routes/'.$this->route->id.'/target')->assertOk();
});

it('preserves active targets and refuses ownership drift', function (): void {
    $this->route->update(['status' => 'active']);
    $this->instance->update(['status' => 'active']);
    $this->deleteJson('/api/v1/routes/'.$this->route->id.'/target')->assertConflict()->assertJsonPath('error.code', 'route.target_conflict');
    expect(fn () => app(RouteTargetState::class)->clear($this->route, []))->toThrow(ResourceOperationException::class, 'owner changed');
    expect($this->route->targets()->pluck('instance_id')->all())->toBe([$this->instance->id]);
    expect(Operation::query()->count())->toBe(0);
});

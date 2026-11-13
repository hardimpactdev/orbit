<?php

declare(strict_types=1);

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Instances\DevelopmentInstanceDestroyProjection;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\RoutePublicationHolds;
use App\Domain\RemotePrograms\ProgramResult;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Domain\Routes\RouteStatus;
use App\Jobs\Operations\PromoteRouteWrites;
use App\Models\Instance;
use App\Models\InstanceRemoval;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

uses(LazilyRefreshDatabase::class);

const RELEASE_RUN = '5b0c7a8e-2f31-4d6a-9c1e-7f4b2a9d0e63';

/** An Instance with a published Route on its app-dev Node, removed with --force: its destroy holds that Node from admission. */
beforeEach(function (): void {
    Queue::fake();
    Http::preventStrayRequests();
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('fixture-root-certificate');
    $this->mock(RemoteProgramRunner::class)->shouldReceive('run')->andReturn(new ProgramResult('inspected', ['dependents' => []], ''));
    $this->node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'release-node', 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '10.44.0.9', 'user' => 'orbit',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key', 'ssh_host_fingerprint' => 'fixture']));
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $this->swamp = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'control', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-control-key']));
    $this->swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Release fixture', 'slug' => 'release-fixture', 'type' => 'node-package',
        'repository_url' => 'https://github.com/example/release-fixture.git', 'default_branch' => 'main', 'root' => 'public']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'fixture',
        'checkout_path' => '/home/orbit/apps/fixture', 'status' => 'active', 'source_layout' => 'worktree', 'root' => 'public',
        'branch' => 'main', 'starting_commit' => str_repeat('a', 40)]);
    $this->route = Route::query()->create(['kind' => 'app', 'project_id' => $project->id, 'node_id' => $this->node->id,
        'provenance' => 'explicit', 'publication' => 'private', 'domain' => 'fixture.release.test', 'status' => 'pending']);
    $this->route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $this->route->update(['status' => 'active', 'sites_published' => true]);
    // The run Swamp started for an admitted Operation.
    $this->running = function (Operation $operation, string $run = RELEASE_RUN): Operation {
        $operation->forceFill(['status' => OperationStatus::Running, 'started_at' => now(), 'state' => [...($operation->state ?? []), 'swamp_run_id' => $run]])->save();

        return $operation->fresh();
    };
    $this->release = fn (Operation $operation, string $action = 'release', string $run = RELEASE_RUN): TestResponse => $this
        ->withServerVariables(['REMOTE_ADDR' => $this->swamp->wireguard_ip])
        ->postJson("/api/v1/direct-operations/{$operation->id}/route-{$action}", ['run_id' => $run]);
    // Another Route write on the same Node, admitted after the destroy.
    $this->routeWrite = function (): Operation {
        $write = Operation::query()->create(['kind' => 'route.create', 'target_type' => 'route', 'target_id' => 999, 'node_id' => $this->node->id,
            'input' => ['route_lifecycle' => ['route_mutations' => ['forward' => ['publications' => [['node_id' => $this->node->id, 'serves_routes' => true]]]]]],
            'status' => OperationStatus::Queued, 'state' => [], 'sequence' => 1, 'attempts' => 0]);
        app(RoutePublicationHolds::class)->admit($write);

        return $write->fresh();
    };
    $this->terminal = fn (Operation $operation, string $status, array $result = []): Operation => app(DevelopmentInstanceDestroyProjection::class)
        ->apply($operation->fresh(), ['status' => $status, 'run_id' => RELEASE_RUN, 'result' => $result,
            'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String()]);
});

function heldBy(int $node): ?int
{
    return DB::table('route_publication_holds')->where('node_id', $node)->value('operation_id');
}

it('lets the next Route write on the Node start once the removal has withdrawn its Route, not at the end of the removal', function (): void {
    $destroy = ($this->running)(app(StartInstanceDestroyAction::class)->execute($this->instance, true));
    expect(heldBy($this->node->id))->toBe($destroy->id);

    // While the withdraw step runs, a second Route write on the Node waits.
    $write = ($this->routeWrite)();
    expect($write->status)->toBe(OperationStatus::Waiting);
    app(RoutePublicationHolds::class)->promote();
    expect($write->fresh()->status)->toBe(OperationStatus::Waiting);

    // The step after a held withdraw gives the Node back while the removal still runs.
    ($this->release)($destroy)->assertOk()->assertJsonPath('data.released', true);
    Queue::assertPushed(PromoteRouteWrites::class);
    expect($destroy->fresh()->status)->toBe(OperationStatus::Running)->and(heldBy($this->node->id))->toBeNull();
    app(RoutePublicationHolds::class)->promote();
    expect($write->fresh()->status)->toBe(OperationStatus::Queued)->and(heldBy($this->node->id))->toBe($write->id)
        ->and(InstanceRemoval::query()->findOrFail($destroy->input['removal_id'])->route_withdrawn_at)->not->toBeNull();

    // A repeated call, after a lost answer, takes nothing from the write that now holds the Node; the removal ends normally.
    ($this->release)($destroy)->assertOk();
    expect(heldBy($this->node->id))->toBe($write->id);
    ($this->terminal)($destroy, 'succeeded', ['route_hold_release' => "{\"route_hold_released\":true,\"http_status\":\"200\"}\n"]);
    expect($this->instance->fresh())->toBeNull()->and(heldBy($this->node->id))->toBe($write->id)
        ->and($destroy->fresh()->result)->toMatchArray(['removed' => true, 'route_hold_released' => true, 'route_hold_release_status' => 200]);
});

it('leaves the Route withdrawn when the removal fails after the release, and its retry waits for no Route write', function (): void {
    $destroy = ($this->running)(app(StartInstanceDestroyAction::class)->execute($this->instance, true));
    ($this->release)($destroy)->assertOk();
    $other = ($this->routeWrite)();
    expect(heldBy($this->node->id))->toBe($other->id);

    ($this->terminal)($destroy, 'failed', ['summary' => ['failed_steps' => ['Remove the source']], 'steps' => ['Remove the source' => ['exit_code' => 1]],
        'route_hold_release' => "{\"route_hold_released\":true,\"http_status\":\"200\"}\n"]);
    expect($destroy->fresh()->status)->toBe(OperationStatus::Failed)->and($this->instance->fresh()->status)->toBe(InstanceState::Removing)
        ->and($this->route->fresh()->status)->toBe(RouteStatus::Retiring)->and($this->route->fresh()->sites_published)->toBeFalse()
        ->and(heldBy($this->node->id))->toBe($other->id);

    // The retry has no Route work: no hold, though another Route write holds the Node, and it skips its withdraw step.
    $retry = app(StartInstanceDestroyAction::class)->execute($this->instance->fresh(), true);
    expect($retry->status)->not->toBe(OperationStatus::Waiting)->and($retry->input['route_withdrawn'])->toBeTrue()
        ->and($retry->input['removal_id'])->toBe($destroy->input['removal_id'])
        ->and(RoutePublicationHolds::nodes($retry))->toBe([])
        ->and(DB::table('route_publication_holds')->where('operation_id', $retry->id)->count())->toBe(0);
    $retry = ($this->running)($retry, RELEASE_RUN);
    // The workflow reads the mark in its withdraw guard (swamp/tests/instance_destroy_route_release_test.ts).
    expect(app(DevelopmentInstanceDestroyProjection::class)->input($retry)['intent']['route_withdrawn'])->toBeTrue();
    ($this->terminal)($retry, 'succeeded');
    expect($this->instance->fresh())->toBeNull()->and(heldBy($this->node->id))->toBe($other->id);
});

it('keeps the hold of a removal whose withdraw did not hold, and of a removal admitted before this release, until its end', function (): void {
    $destroy = ($this->running)(app(StartInstanceDestroyAction::class)->execute($this->instance, true));
    $write = ($this->routeWrite)();

    // An older run has no release step: nothing calls, and the end of the Operation frees the Node.
    ($this->terminal)($destroy, 'failed', ['withdrawal' => ['ready' => false]]);
    expect($destroy->fresh()->error_code)->toBe('route.withdrawal_failed')->and(heldBy($this->node->id))->toBeNull()
        ->and($destroy->fresh()->result)->not->toHaveKey('route_hold_released');
    app(RoutePublicationHolds::class)->promote();
    expect($write->fresh()->status)->toBe(OperationStatus::Queued);

    // A withdraw that did not hold never marks the Route withdrawn: the retry withdraws again under a hold.
    expect(InstanceRemoval::query()->findOrFail($destroy->input['removal_id'])->route_withdrawn_at)->toBeNull();
    $finished = app(RoutePublicationHolds::class);
    DB::table('route_publication_holds')->delete();
    $retry = app(StartInstanceDestroyAction::class)->execute($this->instance->fresh(), true);
    expect(heldBy($this->node->id))->toBe($retry->id)->and($finished::nodes($retry))->toBe([$this->node->id]);
});

it('reports a release the Gateway refused, and refuses a step hold for a removal and an early release for another kind', function (): void {
    $destroy = ($this->running)(app(StartInstanceDestroyAction::class)->execute($this->instance, true));
    ($this->release)($destroy, 'hold')->assertForbidden()->assertJsonPath('error.code', 'operation.route_hold_forbidden');
    ($this->release)($destroy, 'release', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee')->assertStatus(409);
    expect(heldBy($this->node->id))->toBe($destroy->id);

    $write = ($this->routeWrite)();
    ($this->release)(($this->running)($write, 'cccccccc-bbbb-cccc-dddd-eeeeeeeeeeee'), 'release', 'cccccccc-bbbb-cccc-dddd-eeeeeeeeeeee')->assertForbidden();

    ($this->terminal)($destroy, 'succeeded', ['route_hold_release' => "{\"route_hold_released\":false,\"http_status\":\"403\"}\n"]);
    expect($destroy->fresh()->result)->toMatchArray(['route_hold_released' => false, 'route_hold_release_status' => 403])
        ->and(heldBy($this->node->id))->toBeNull();
});

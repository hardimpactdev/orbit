<?php

declare(strict_types=1);

use App\Actions\Doctor\RouteDoctorProbe;
use App\Actions\Instances\DestroyInstanceAction;
use App\Actions\Routes\ConvergeRouteAction;
use App\Actions\Routes\ConvergeRouteTargetSetAction;
use App\Data\Doctor\DoctorNodeReportData;
use App\Data\Doctor\DoctorReportData;
use App\Data\Routes\SetRouteTargetsData;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Nodes\RoleName;
use App\Domain\Problems\ProblemRecovery;
use App\Domain\Problems\ProblemSource;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketRouteInspector;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Caddy\Build\NodeCaddyBuilds;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\ProblemFingerprint;
use App\Models\Project;
use App\Models\Route;
use App\Models\WebSocketApp;
use App\Models\WebSocketAppTarget;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Http::fake();
    $this->inspector = Mockery::mock(WebSocketRouteInspector::class);
    $this->inspector->shouldReceive('hasCollisions')->andReturn(false)->byDefault();
    app()->instance(WebSocketRouteInspector::class, $this->inspector);
});

/** @return array{Node, Instance, Route, WebSocketApp} */
function public_reverb_fixture(): array
{
    $node = Node::query()->create(['public_ssh_host' => '192.0.2.4', 'name' => 'app-prod', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.4', 'status' => 'active']);
    test()->markAsGateway($node);
    test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $cluster = Cluster::query()->create(['name' => 'prod', 'state' => 'active', 'tld' => 'prod.orbit']);
    orbit_test_set_app_placement_role($node, true);
    $node->update(['cluster_id' => $cluster->id]);
    foreach ([RoleName::Router, RoleName::Ingress] as $role) {
        $node->roles()->create(['role' => $role, 'cluster_id' => $cluster->id, 'status' => 'active']);
    }
    $project = Project::query()->create(['repository_url' => 'https://example.test/mealou.git', 'default_branch' => 'main', 'name' => 'Mealou', 'slug' => 'mealou', 'root' => 'public']);
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'prod', 'status' => 'active',
        'provisioning_step' => 'active', 'source_is_laravel' => true,
        'production_user' => 'orbit-mealou', 'production_home' => '/home/orbit-mealou',
        'checkout_path' => '/home/orbit-mealou/releases/one', 'selected_php_version' => '8.5',
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id, 'cluster_id' => $cluster->id, 'domain' => 'mealou.app',
        'publication' => 'public', 'provenance' => 'explicit', 'status' => 'pending',
        'replacement_step' => RouteReplacementStep::PublicActivated,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active']);
    $app = WebSocketApp::query()->create(['name' => 'mealou-prod', 'app_id' => 'mealou-id', 'key' => 'mealou-key', 'secret' => 'mealou-secret']);

    return [$node, $instance, $route, $app];
}

function public_reverb_url(Instance $instance, string $name = 'mealou-prod'): string
{
    return '/api/v1/instances/'.$instance->id.'/websocket-apps/'.$name;
}

it('publishes the existing app Route and preserves its host on repeat attachment and secret rotation', function (): void {
    [$node, $instance, $route, $app] = public_reverb_fixture();
    $this->putJson(public_reverb_url($instance), ['public' => true])->assertOk();
    expect(WebSocketAppTarget::query()->sole()->route_id)->toBe($route->id)
        ->and($instance->environmentValues()->where('env_key', 'REVERB_HOST')->sole()->env_value)->toBe('mealou.app')
        ->and(app(NodeCaddyBuilds::class)->built)->toBe(['app-prod']);
    $sites = app(DevelopmentSiteRepository::class)->forNode($node);
    expect($sites->where('domain', 'mealou.app')->sole()->websocketKey)->toBe('mealou-key');
    $this->putJson(public_reverb_url($instance))->assertOk();
    $old = $app->secret;
    $this->postJson('/api/v1/websocket-apps/mealou-prod/rotate')->assertStatus(202);
    expect($app->refresh()->secret)->not->toBe($old)
        ->and($instance->environmentValues()->where('env_key', 'REVERB_APP_SECRET')->sole()->env_value)->toBe($app->secret)
        ->and($instance->environmentValues()->where('env_key', 'REVERB_HOST')->sole()->env_value)->toBe('mealou.app')
        ->and(Route::query()->count())->toBe(1);
});

it('withdraws the Pusher handler when detached and retains the app Route', function (): void {
    [$node, $instance, $route] = public_reverb_fixture();
    $this->putJson(public_reverb_url($instance), ['public' => true])->assertOk();
    $this->deleteJson(public_reverb_url($instance))->assertOk();
    expect(app(DevelopmentSiteRepository::class)->forNode($node)->where('domain', 'mealou.app')->sole()->websocketKey)->toBeNull()
        ->and(WebSocketAppTarget::query()->count())->toBe(0)
        ->and(Route::query()->count())->toBe(1)
        ->and(app(NodeCaddyBuilds::class)->built)->toBe(['app-prod', 'app-prod']);
});

it('rejects a private Route or a declared Laravel prefix before changing environment', function (bool $private): void {
    [, $instance, $route] = public_reverb_fixture();
    if ($private) {
        $route->update(['publication' => 'private', 'replacement_step' => null]);
    } else {
        $this->inspector->shouldReceive('hasCollisions')->once()->andReturn(true);
    }
    $this->putJson(public_reverb_url($instance), ['public' => true])->assertConflict()
        ->assertJsonPath('error.code', $private ? 'websocket.public_route_invalid' : 'websocket.route_collision');
    expect(WebSocketAppTarget::query()->count())->toBe(0)->and($instance->environmentValues()->count())->toBe(0);
})->with([true, false]);

it('rejects an unverifiable release before publishing', function (): void {
    [$node, $instance] = public_reverb_fixture();
    $this->inspector->shouldReceive('hasCollisions')->andThrow(new ResourceOperationException('websocket.route_inspection_failed', 'Unavailable', 409));
    $this->putJson(public_reverb_url($instance), ['public' => true])->assertConflict()->assertJsonPath('error.code', 'websocket.route_inspection_failed');
    expect(app(NodeCaddyBuilds::class)->built)->toBe([]);
});

it('reports a collision introduced after attachment through Doctor', function (bool $failed): void {
    [$node, $instance] = public_reverb_fixture();
    $this->putJson(public_reverb_url($instance), ['public' => true])->assertOk();
    if ($failed) {
        $this->inspector->shouldReceive('hasCollisions')->andThrow(new ResourceOperationException('websocket.route_inspection_failed', 'Unavailable', 409));
    } else {
        $this->inspector->shouldReceive('hasCollisions')->andReturn(true);
    }
    $report = app(RouteDoctorProbe::class)->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, null, null, null)));
    expect(collect($report->issues)->pluck('code')->all())->toBe([$failed ? 'route.websocket_inspection_failed' : 'route.websocket_collision']);
})->with([false, true]);

it('keeps stored public intent after a build failure and repairs it on repeat attachment', function (): void {
    [$node, $instance, $route] = public_reverb_fixture();
    app(NodeCaddyBuilds::class)->failNext('app-prod');
    $this->putJson(public_reverb_url($instance), ['public' => true])->assertStatus(502)->assertJsonPath('error.code', 'websocket.route_publication_failed');
    expect(WebSocketAppTarget::query()->sole()->route_id)->toBe($route->id);
    $this->putJson(public_reverb_url($instance), ['public' => true])->assertOk();
    expect(app(NodeCaddyBuilds::class)->built)->toBe(['app-prod', 'app-prod']);
});

it('requires removing public attachments before changing domain or publication', function (array $proposal): void {
    [, $instance, $route] = public_reverb_fixture();
    $this->putJson(public_reverb_url($instance), ['public' => true])->assertOk();
    $this->patchJson('/api/v1/routes/'.$route->id, $proposal)->assertConflict()->assertJsonPath('error.code', 'websocket.route_in_use');
    expect($route->refresh()->domain)->toBe('mealou.app')->and($route->publication->value)->toBe('public');
})->with([[['domain' => 'other.app']], [['publication' => 'private']]]);

it('accepts explicit private projection and rejects unsupported attachment fields', function (): void {
    [$node, $instance] = public_reverb_fixture();
    $this->putJson(public_reverb_url($instance), ['route_id' => 1])->assertUnprocessable();
    $this->putJson(public_reverb_url($instance), ['public' => 'true'])->assertUnprocessable();
    $this->putJson(public_reverb_url($instance), ['public' => true])->assertOk();
    $this->putJson(public_reverb_url($instance), ['public' => false])->assertOk();
    expect(WebSocketAppTarget::query()->sole()->route_id)->toBeNull()
        ->and($instance->environmentValues()->where('env_key', 'REVERB_HOST')->sole()->env_value)->toBe('reverb.orbit')
        ->and(app(DevelopmentSiteRepository::class)->forNode($node)->where('domain', 'mealou.app')->sole()->websocketKey)->toBeNull();
});

it('rejects another Reverb app on the same public Route', function (): void {
    [$node, $instance, $route] = public_reverb_fixture();
    $this->putJson(public_reverb_url($instance), ['public' => true])->assertOk();
    $otherNode = Node::query()->create(['name' => 'other-prod', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.5', 'wireguard_ip' => '10.44.0.5', 'status' => 'active', 'cluster_id' => $node->cluster_id]);
    orbit_test_set_app_placement_role($otherNode, true);
    $other = $instance->replicate();
    $other->node_id = $otherNode->id;
    $other->name = 'other';
    $other->save();
    $route->targets()->create(['instance_id' => $other->id, 'position' => 1]);
    WebSocketApp::query()->create(['name' => 'hauzer-prod', 'app_id' => 'hauzer-id', 'key' => 'hauzer-key', 'secret' => 'hauzer-secret']);
    $this->putJson(public_reverb_url($other, 'hauzer-prod'), ['public' => true])->assertConflict()->assertJsonPath('error.code', 'websocket.route_app_conflict');
    expect($other->environmentValues()->count())->toBe(0);
});

it('reports an unverifiable collision check without SSH when the serving Node is unreachable', function (): void {
    [$node, $instance] = public_reverb_fixture();
    $this->putJson(public_reverb_url($instance), ['public' => true])->assertOk();
    $this->inspector->shouldNotReceive('hasCollisions');
    $report = app(RouteDoctorProbe::class)->inspect(new DoctorNodeContext($node, new NodeInspectionData(false, null, null, null)));
    expect(collect($report->issues)->pluck('code')->all())->toBe(['route.websocket_inspection_failed']);
});

it('refuses target removal and Instance removal before changing a public attachment', function (): void {
    [, $instance, $route] = public_reverb_fixture();
    $this->putJson(public_reverb_url($instance), ['public' => true])->assertOk();
    $this->deleteJson('/api/v1/routes/'.$route->id.'/target')->assertConflict()->assertJsonPath('error.code', 'websocket.route_in_use');
    expect(fn () => app(DestroyInstanceAction::class)->execute($instance, force: true))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('websocket.route_in_use');
    });
    expect(WebSocketAppTarget::query()->count())->toBe(1)->and($route->targets()->count())->toBe(1);
});

it('resolves a previous Doctor collision after removing public Reverb without inspecting the app', function (): void {
    [$node, $instance, $route] = public_reverb_fixture();
    $this->putJson(public_reverb_url($instance), ['public' => true])->assertOk();
    $problem = ProblemFingerprint::query()->create([
        'fingerprint' => 'doctor|route.websocket_collision|route|'.$route->id,
        'source' => ProblemSource::Doctor, 'occurrences' => 0,
        'evidence' => ['doctor_node' => (string) $node->id, 'doctor_family' => 'route', 'doctor_code' => 'route.websocket_collision',
            'doctor_resource' => (string) $route->id, 'doctor_observed_at' => now()->toISOString()],
    ]);
    $this->deleteJson(public_reverb_url($instance))->assertOk();
    $this->travel(1)->minutes();
    $this->inspector->shouldNotReceive('hasCollisions');
    $family = app(RouteDoctorProbe::class)->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, null, null, null)));
    app(ProblemRecovery::class)->doctor(DoctorReportData::fromNodes([
        DoctorNodeReportData::fromFamilies($node->id, $node->name, [$family]),
    ]), now());
    expect($problem->refresh()->evidence['resolution'] ?? null)->toBe('fresh_doctor_success');
});

it('replaces its own public Reverb app without requiring detach', function (): void {
    [$node, $instance, $route] = public_reverb_fixture();
    $this->putJson(public_reverb_url($instance), ['public' => true])->assertOk();
    $other = WebSocketApp::query()->create(['name' => 'mealou-next', 'app_id' => 'next-id', 'key' => 'next-key', 'secret' => 'next-secret']);

    $this->putJson(public_reverb_url($instance, 'mealou-next'), ['public' => true])->assertOk();

    expect(WebSocketAppTarget::query()->sole()->websocket_app_id)->toBe($other->id)
        ->and(WebSocketAppTarget::query()->sole()->route_id)->toBe($route->id)
        ->and($instance->environmentValues()->where('env_key', 'REVERB_HOST')->sole()->env_value)->toBe('mealou.app')
        ->and(app(DevelopmentSiteRepository::class)->forNode($node)->where('domain', 'mealou.app')->sole()->websocketKey)->toBe('next-key');
});

it('rejects public attachment until the Route transition has finished', function (array $transition): void {
    [$node, $instance, $route] = public_reverb_fixture();
    if (isset($transition['transition_node_id'])) {
        $transition['transition_node_id'] = $node->id;
    }
    $route->update($transition);

    $this->putJson(public_reverb_url($instance), ['public' => true])->assertConflict()->assertJsonPath('error.code',
        isset($transition['failed_step']) ? 'env.owner_unavailable' : 'websocket.public_route_invalid');

    expect(WebSocketAppTarget::query()->count())->toBe(0)
        ->and($instance->environmentValues()->count())->toBe(0)
        ->and(app(NodeCaddyBuilds::class)->built)->toBe([]);
})->with([
    'retained target intent' => [['target_set_intent' => ['targets' => [], 'dispositions' => []]]],
    'partial target set' => [['target_set_step' => 'router-published']],
    'placement transition' => [['transition_node_id' => 999]],
    'failed transition' => [['failed_step' => 'router-published', 'error_code' => 'route.failed']],
]);

it('accepts public attachment after a target-set transition completes', function (): void {
    [, $instance, $route] = public_reverb_fixture();
    $route->update(['target_set_step' => 'completed']);

    $this->putJson(public_reverb_url($instance), ['public' => true])->assertOk();

    expect(WebSocketAppTarget::query()->sole()->route_id)->toBe($route->id);
});

it('guards direct domain convergence before replacement starts', function (): void {
    [, $instance, $route] = public_reverb_fixture();
    $this->putJson(public_reverb_url($instance), ['public' => true])->assertOk();

    expect(fn () => app(ConvergeRouteAction::class)->execute($route, 'other.app'))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('websocket.route_in_use');
    });

    expect(Route::query()->count())->toBe(1)->and($route->refresh()->domain)->toBe('mealou.app');
});

it('guards target-set changes before reserving intent', function (): void {
    [, $instance, $route] = public_reverb_fixture();
    $this->putJson(public_reverb_url($instance), ['public' => true])->assertOk();

    expect(fn () => app(ConvergeRouteTargetSetAction::class)->execute($route, new SetRouteTargetsData([], [])))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('websocket.route_in_use');
    });

    expect($route->refresh()->target_set_intent)->toBeNull()->and($route->targets()->count())->toBe(1);
});

it('guards a changed web root but accepts an unchanged web root', function (): void {
    [, $instance, $route] = public_reverb_fixture();
    $this->putJson(public_reverb_url($instance), ['public' => true])->assertOk();

    $this->patchJson('/api/v1/routes/'.$route->id, ['web_root' => 'other/public'])->assertConflict()->assertJsonPath('error.code', 'websocket.route_in_use');
    $this->patchJson('/api/v1/routes/'.$route->id, ['web_root' => null])->assertOk();

    expect($route->refresh()->web_root)->toBeNull()->and(WebSocketAppTarget::query()->sole()->route_id)->toBe($route->id);
});

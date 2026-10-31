<?php

declare(strict_types=1);

use App\Actions\Instances\DestroyInstanceAction;
use App\Domain\Analytics\AnalyticsDisableProjection;
use App\Domain\Analytics\AnalyticsIntentProjection;
use App\Domain\Analytics\AnalyticsStatsKeyStore;
use App\Domain\Clusters\ClusterState;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Activity;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use App\Models\RouteAnalyticsTracking;

beforeEach(function (): void {
    $this->gateway = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    $this->markAsGateway($this->gateway);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1']);

    $this->orbitApp = Project::query()->create([
        'name' => 'Shop',
        'slug' => 'shop',
        'repository_url' => 'https://example.test/shop.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $this->cluster = Cluster::query()->create(['name' => 'edge', 'tld' => 'edge.test', 'state' => ClusterState::Active]);
    $this->router = instance_analytics_node('edge-router', '10.44.0.20', $this->cluster, RoleName::Router);
    $this->ingress = instance_analytics_node('edge-ingress', '10.44.0.30', $this->cluster, RoleName::Ingress);
    $this->workload = instance_analytics_node('edge-prod', '10.44.0.31', $this->cluster, RoleName::AppProd);
    $this->analytics = instance_analytics_node('services', '10.44.0.40', null, RoleName::Analytics);
    $this->instance = instance_analytics_instance($this->orbitApp, $this->workload, 'production');
    $this->appRoute = instance_analytics_app_route($this->instance, 'shop.example.com', RoutePublication::Public);
    $this->url = "/api/v1/instances/{$this->instance->id}/analytics";
});

describe('instance:analytics:show', function (): void {
    it('reports a Project instance without tracking', function (): void {
        $this->getJson($this->url)
            ->assertOk()
            ->assertJsonPath('data', [
                'instance_id' => $this->instance->id,
                'enabled' => false,
                'domain' => 'shop.example.com',
                'dashboard_url' => 'https://analytics.orbit',
                'hosts' => [],
                'snippet' => null,
            ])
            ->assertJsonStructure(['meta' => ['request_id']]);
    });

    it('reports no dashboard without an active analytics role and no domain without a Route', function (): void {
        $this->analytics->roles()->update(['status' => LifecycleStatus::Provisioning]);
        $bare = instance_analytics_extra_instance('bare');

        $this->getJson("/api/v1/instances/{$bare->id}/analytics")
            ->assertOk()
            ->assertJsonPath('data.instance_id', $bare->id)
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.domain', null)
            ->assertJsonPath('data.dashboard_url', null)
            ->assertJsonPath('data.hosts', [])
            ->assertJsonPath('data.snippet', null);
    });

    it('answers 404 for an unknown App instance', function (): void {
        $this->getJson('/api/v1/instances/999999/analytics')->assertNotFound();
    });
});

describe('instance:analytics:enable', function (): void {
    it('admits one direct parent with desired hosts and no host execution', function (): void {
        $socket = analytics_intent_socket();
        $response = $this->postJson($this->url)->assertAccepted()->assertJsonPath('data.kind', 'analytics.enable');
        $operation = Operation::query()->findOrFail($response->json('data.id'));
        expect($operation->input['site'])->toBe(['domain' => 'shop.example.com', 'previous_domain' => null, 'timezone' => 'Europe/Amsterdam', 'goals' => []])
            ->and($operation->input['hosts'])->toBe(['analytics.shop.example.com'])
            ->and($operation->input['plausible_credentials'])->toBe(['$secret' => 'analytics.plausible_credentials', 'id' => $this->analytics->id])
            ->and($socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-analytics-enable-direct')
            ->and(Operation::query()->count())->toBe(1)
            ->and(RouteAnalyticsTracking::query()->count())->toBe(1)
            ->and($operation->input['tracking']['publish'])->toHaveCount(1)
            ->and($operation->input['tracking']['certificates'])->toHaveCount(1)
            ->and($operation->input['tracking']['certificates'][0]['scope'])->toEndWith('-router')
            ->and($operation->input['tracking']['certificates'][0])->not->toHaveKey('home')
            ->and($operation->input['tracking']['certificates'][0]['user'])->toBe('orbit')
            ->and($operation->input['tracking']['router_node_ids'])->toBe([$this->router->id])
            ->and($operation->input['tracking']['ingress_node_ids'])->toBe([$this->ingress->id])
            ->and($operation->input['participant_node_ids'])->toContain($this->analytics->id, $this->router->id, $this->ingress->id)
            ->and($operation->state)->not->toHaveKey('analytics_writer');
    });

    it('projects publication and site identity only after the native effects succeed', function (): void {
        $operation = analytics_intent_start($this);
        $route = Route::query()->where('kind', RouteKind::AnalyticsTracking)->sole();
        expect($route->status)->toBe(RouteStatus::Pending)->and($route->sites_published)->toBeFalse();
        $projection = app(AnalyticsIntentProjection::class);
        $projection->apply($operation, analytics_intent_result('running', ['certificates_ready' => true]));
        expect($route->fresh()->sites_published)->toBeTrue()
            ->and($route->fresh()->replacement_step)->toBeNull();
        $projection->apply($operation, analytics_intent_result('running', ['public_verified' => true]));
        expect($route->fresh()->replacement_step)->toBe(RouteReplacementStep::PublicActivated);
        $projection->apply($operation, analytics_intent_result('succeeded', analytics_intent_success()));
        $this->getJson($this->url)->assertOk()->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.hosts.0.host', 'analytics.shop.example.com')
            ->assertJsonPath('data.hosts.0.status', 'active');
        expect($route->fresh()->replacement_step)->toBe(RouteReplacementStep::PublicActivated);
        $settings = app(SettingRepository::class);
        $scope = new SettingScope(SettingScopeType::Gateway);
        $settings->put($scope, 'analytics.site.'.$this->instance->id, 'newer-binding');
        // Exact terminal replay must not overwrite a later binding.
        $projection->apply($operation, analytics_intent_result('succeeded', analytics_intent_success()));
        expect($settings->get($scope, 'analytics.site.'.$this->instance->id))->toBe('newer-binding');
    });

    it('converges the exact host set and retains old router and ingress cleanup targets', function (): void {
        $old = instance_analytics_tracking($this->instance, 'old.shop.example.com');
        $operation = analytics_intent_start($this, ['hosts' => [' Stats.Shop.Example.Com ']]);
        $plan = $operation->fresh()->input['tracking'];
        expect($plan['remove'])->toBe([$old->id])->and($plan['cleanup'])->toHaveCount(1)
            ->and($plan['caddy_node_ids'])->toBe([$this->router->id, $this->ingress->id])
            ->and($operation->input['hosts'])->toBe(['stats.shop.example.com']);
        app(AnalyticsIntentProjection::class)->apply($operation, analytics_intent_result('running', ['certificates_ready' => true]));
        app(AnalyticsIntentProjection::class)->apply($operation, analytics_intent_result('succeeded', analytics_intent_success()));
        expect(Route::query()->whereKey($old->id)->exists())->toBeFalse()
            ->and(Route::query()->where('kind', RouteKind::AnalyticsTracking)->pluck('domain')->all())->toBe(['stats.shop.example.com']);
    });

    it('mirrors a private authoritative Route without admitting an ingress step', function (): void {
        $this->appRoute->update(['publication' => RoutePublication::Private]);
        $operation = analytics_intent_start($this);
        expect($operation->fresh()->input['tracking']['caddy_node_ids'])->toBe([$this->router->id])
            ->and($operation->fresh()->input['tracking']['certificates'])->toHaveCount(1);
    });

    it('keeps the existing Router leaf while retiring only the public edge on a private change', function (): void {
        $route = instance_analytics_tracking($this->instance);
        $this->appRoute->update(['publication' => RoutePublication::Private]);

        $operation = analytics_intent_start($this);

        expect($operation->input['tracking']['cleanup'])->toBe([])
            ->and($operation->input['tracking']['ingress_node_ids'])->toBe([])
            ->and($operation->input['tracking']['cleanup_caddy_node_ids'])->toBe([$this->router->id, $this->ingress->id])
            ->and($route->fresh()->publication)->toBe(RoutePublication::Private);
    });

    it('needs no retiring placement publication when the exact host set is already current', function (): void {
        instance_analytics_tracking($this->instance);

        $operation = analytics_intent_start($this);

        expect($operation->input['tracking']['cleanup_caddy_node_ids'])->toBe([])
            ->and($operation->input['tracking']['cleanup'])->toBe([]);
    });

    it('keeps retiring placement identity until native cleanup and successful completion', function (): void {
        $oldCluster = Cluster::query()->create(['name' => 'old-edge', 'tld' => 'old.test', 'state' => ClusterState::Active]);
        $oldRouter = instance_analytics_node('old-router', '10.44.0.80', $oldCluster, RoleName::Router);
        $oldIngress = instance_analytics_node('old-ingress', '10.44.0.81', $oldCluster, RoleName::Ingress);
        $route = instance_analytics_tracking($this->instance);
        $route->update(['cluster_id' => $oldCluster->id]);

        $operation = analytics_intent_start($this);
        $plan = $operation->input['tracking'];
        expect($route->fresh()->cluster_id)->toBe($this->cluster->id)
            ->and($route->fresh()->transition_cluster_id)->toBe($oldCluster->id)
            ->and($plan['router_node_ids'])->toBe([$this->router->id])
            ->and($plan['ingress_node_ids'])->toBe([$this->ingress->id])
            ->and($plan['cleanup_caddy_node_ids'])->toBe([$oldRouter->id, $oldIngress->id])
            ->and($plan['cleanup'])->toHaveCount(1)
            ->and($plan['cleanup'][0]['node_id'])->toBe($oldRouter->id)
            ->and($plan['cleanup'][0]['in_use'])->toBeFalse();
        $projection = app(AnalyticsIntentProjection::class);
        $projection->apply($operation, analytics_intent_result('running', ['certificates_ready' => true, 'public_verified' => true]));
        expect($route->fresh()->transition_cluster_id)->toBe($oldCluster->id);
        $projection->apply($operation, analytics_intent_result('running', ['placement_cleanup' => true]));
        expect($route->fresh()->replacement_step)->toBe(RouteReplacementStep::Cleanup)
            ->and($route->fresh()->transition_cluster_id)->toBe($oldCluster->id);
        $projection->apply($operation, analytics_intent_result('succeeded', analytics_intent_success()));
        expect($route->fresh()->transition_cluster_id)->toBeNull()
            ->and($route->fresh()->transition_dns_moved_at)->toBeNull()
            ->and($route->fresh()->status)->toBe(RouteStatus::Active);
    });

    it('preserves its old binding and retiring Routes when native publication fails', function (): void {
        $settings = app(SettingRepository::class);
        $scope = new SettingScope(SettingScopeType::Gateway);
        $binding = json_encode(['domain' => 'old.example.com', 'timezone' => 'UTC']);
        $settings->put($scope, 'analytics.site.'.$this->instance->id, $binding);
        $old = instance_analytics_tracking($this->instance, 'old.shop.example.com');
        $operation = analytics_intent_start($this);

        app(AnalyticsIntentProjection::class)->apply($operation, analytics_intent_result('failed', [], 'analytics.change_failed'));

        expect($settings->get($scope, 'analytics.site.'.$this->instance->id))->toBe($binding)
            ->and($old->fresh()->status)->toBe(RouteStatus::Retiring)
            ->and(Route::query()->where('domain', 'analytics.shop.example.com')->sole()->status)->toBe(RouteStatus::Pending);
    });

    it('refuses absent analytics and authoritative domains before submitting a run', function (): void {
        $socket = analytics_intent_socket();
        $this->analytics->roles()->update(['status' => LifecycleStatus::Provisioning]);
        $this->postJson($this->url)->assertConflict()->assertJsonPath('error.code', 'analytics.role_missing');
        $this->analytics->roles()->update(['status' => LifecycleStatus::Active]);
        $this->appRoute->update(['status' => RouteStatus::Pending]);
        $this->postJson($this->url)->assertUnprocessable()->assertJsonPath('error.code', 'analytics.domain_required');
        expect($socket->sent)->toBe([]);
    });

    it('refuses another Route hostname and writes none of the desired set', function (): void {
        analytics_intent_socket();
        $this->postJson($this->url, ['hosts' => ['fresh.shop.example.com', 'shop.example.com']])
            ->assertConflict()->assertJsonPath('error.code', 'analytics.host_taken');
        expect(RouteAnalyticsTracking::query()->count())->toBe(0);
    });

    it('rejects malformed input before an Operation exists', function (array $body): void {
        analytics_intent_socket();
        $this->postJson($this->url, $body)->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');
        expect(Operation::query()->count())->toBe(0);
    })->with([
        'singular host' => [['host' => 'stats.shop.example.com']],
        'duplicate' => [['hosts' => ['stats.shop.example.com', 'Stats.Shop.Example.Com.']]],
        'invalid host' => [['hosts' => ['https://stats.shop.example.com']]],
        'timezone' => [['timezone' => 'Invalid/Timezone']],
        'empty property name' => [['goals' => [['goal_type' => 'Goal.CustomEvent', 'goal' => ['event_name' => 'Signup', 'custom_props' => ['' => 'paid']]]]]],
        'list properties' => [['goals' => [['goal_type' => 'Goal.CustomEvent', 'goal' => ['event_name' => 'Signup', 'custom_props' => ['paid']]]]]],
        'missing event' => [['goals' => [['goal_type' => 'Goal.CustomEvent', 'goal' => []]]]],
        'missing currency' => [['goals' => [['goal_type' => 'Goal.Revenue', 'goal' => ['event_name' => 'Sale']]]]],
        'foreign field' => [['goals' => [['goal_type' => 'Goal.Pageview', 'goal' => ['path' => '/', 'event_name' => 'x']]]]],
    ]);

    it('normalizes empty goal properties to an absent object field before native admission', function (): void {
        analytics_intent_socket();
        $id = $this->postJson($this->url, ['goals' => [['goal_type' => 'Goal.CustomEvent', 'goal' => ['event_name' => 'Signup', 'custom_props' => []]]]])->assertAccepted()->json('data.id');
        expect(Operation::query()->findOrFail($id)->input['site']['goals'])->toBe([['goal_type' => 'Goal.CustomEvent', 'goal' => ['event_name' => 'Signup']]]);
    });

    it('keeps running observations out of the projected result', function (): void {
        $operation = analytics_intent_start($this);

        app(AnalyticsIntentProjection::class)->apply($operation, analytics_intent_result('running', ['ready' => false]));

        expect($operation->fresh()->result)->toBe([])
            ->and($operation->fresh()->status)->toBe(OperationStatus::Running);
    });

    it('projects bounded domain observations and discards unrecognized result fields', function (): void {
        $operation = analytics_intent_start($this);

        app(AnalyticsIntentProjection::class)->apply($operation, analytics_intent_result('succeeded', [
            ...analytics_intent_success(), 'extra' => 'private-sentinel',
        ]));

        expect($operation->fresh()->result)->toEqual(analytics_intent_success())
            ->and(json_encode($operation->fresh()->toArray()))->not->toContain('private-sentinel');
    });

});

describe('instance:analytics:disable', function (): void {
    beforeEach(function (): void {
        foreach ([$this->router, $this->ingress] as $node) {
            $node->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAINqVuK+N4gL/sDvGNKnGrYhEtEFnZx9hvH+134PsQgND']);
        }
    });
    it('admits one native parent and removes only its exact observed tracking set on completion', function (): void {
        $other = instance_analytics_extra_instance('other');
        instance_analytics_app_route($other, 'other.example.com', RoutePublication::Public);
        $retained = instance_analytics_tracking($other);
        $first = instance_analytics_tracking($this->instance);
        $second = instance_analytics_tracking($this->instance, 'stats.shop.example.com');
        analytics_intent_socket();
        $id = $this->deleteJson($this->url)->assertAccepted()->assertJsonPath('data.kind', 'analytics.disable')->json('data.id');
        $operation = Operation::query()->findOrFail($id);
        expect($operation->input)->not->toHaveKey('plausible_credentials')
            ->and(RouteAnalyticsTracking::query()->count())->toBe(3)
            ->and($operation->input['tracking']['remove'])->toBe([$first->id, $second->id]);
        $projection = app(AnalyticsDisableProjection::class);
        $projection->apply($operation, analytics_intent_result('running'));
        expect($operation->fresh()->input['tracking']['cleanup'])->toHaveCount(2)
            ->and($first->fresh()->status)->toBe(RouteStatus::Retiring)
            ->and($second->fresh()->sites_published)->toBeFalse();
        $result = ['ready' => true, 'enabled' => false, 'domain' => null, 'timezone' => null, 'detached' => true];
        $projection->apply($operation, analytics_intent_result('succeeded', $result));
        expect(Route::query()->where('kind', RouteKind::AnalyticsTracking)->pluck('id')->all())->toBe([$retained->id])
            ->and(Route::query()->whereKey($this->appRoute->id)->exists())->toBeTrue()
            ->and(Operation::query()->count())->toBe(1);
        $this->getJson($this->url)->assertOk()->assertJsonPath('data.enabled', false)->assertJsonPath('data.hosts', []);
    });

    it('detaches its binding without Plausible credentials, active role or Instance domain', function (): void {
        $settings = app(SettingRepository::class);
        $scope = new SettingScope(SettingScopeType::Gateway);
        $settings->put($scope, 'analytics.site.'.$this->instance->id, json_encode(['domain' => 'old.example.com', 'timezone' => 'UTC']));
        $this->analytics->roles()->update(['status' => LifecycleStatus::Failed]);
        $this->appRoute->update(['status' => RouteStatus::Retiring, 'sites_published' => false]);
        analytics_intent_socket();
        $id = $this->deleteJson($this->url)->assertAccepted()->json('data.id');
        $operation = Operation::query()->findOrFail($id);
        expect($operation->node_id)->toBe($this->workload->id);
        $projection = app(AnalyticsDisableProjection::class);
        $projection->apply($operation, analytics_intent_result('running'));
        expect($operation->fresh()->input['tracking']['dns_node_ids'])->toBe([]);
        $projection->apply($operation, analytics_intent_result('succeeded', ['ready' => true, 'enabled' => false, 'domain' => 'old.example.com', 'timezone' => 'UTC', 'detached' => true]));
        expect($settings->get($scope, 'analytics.site.'.$this->instance->id))->toBeNull();
        $projection->apply($operation, analytics_intent_result('succeeded', ['ready' => true, 'enabled' => false, 'domain' => 'old.example.com', 'timezone' => 'UTC', 'detached' => true]));
    });

});

describe('generic Route commands on a tracking Route', function (): void {
    it('cannot create the kind through route:create', function (): void {
        $this->postJson('/api/v1/routes', [
            'kind' => 'analytics_tracking',
            'domain' => 'analytics.shop.example.com',
            'publication' => 'public',
            'cluster_id' => $this->cluster->id,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');

        $this->postJson('/api/v1/routes', [
            'domain' => 'analytics.shop.example.com',
            'publication' => 'public',
            'cluster_id' => $this->cluster->id,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'validation.failed');

        expect(Route::query()->where('domain', 'analytics.shop.example.com')->exists())->toBeFalse();
    });

    it('refuses route:update and both route:target commands', function (): void {
        $routeId = instance_analytics_tracking($this->instance)->id;

        $this->patchJson("/api/v1/routes/{$routeId}", ['publication' => 'private'])
            ->assertConflict();
        $this->patchJson("/api/v1/routes/{$routeId}", ['domain' => 'moved.shop.example.com'])
            ->assertConflict();
        $this->putJson("/api/v1/routes/{$routeId}/target", ['instance_id' => $this->instance->id])
            ->assertConflict();
        $this->deleteJson("/api/v1/routes/{$routeId}/target")
            ->assertConflict()
            ->assertJsonPath('error.code', 'route.kind_unsupported');

        $route = Route::query()->findOrFail($routeId);
        expect($route->domain)->toBe('analytics.shop.example.com')
            ->and($route->publication)->toBe(RoutePublication::Public)
            ->and($route->targets()->count())->toBe(0)
            ->and(Operation::query()->count())->toBe(0);
    });

    it('lists the tracking Route with its App instance', function (): void {
        $routeId = instance_analytics_tracking($this->instance)->id;

        $this->getJson('/api/v1/routes')
            ->assertOk()
            ->assertJsonPath('data.0.id', $routeId)
            ->assertJsonPath('data.0.kind', 'analytics_tracking')
            ->assertJsonPath('data.0.analytics_instance_id', $this->instance->id)
            ->assertJsonPath('data.1.analytics_instance_id', null);
    });
});

describe('guards around a tracking host', function (): void {
    it('refuses to remove the analytics role while a tracking host exists', function (): void {
        instance_analytics_tracking($this->instance);

        $this->deleteJson("/api/v1/nodes/{$this->analytics->id}/roles/analytics", ['force' => true])
            ->assertConflict()
            ->assertJsonPath('error.code', 'analytics.tracking_hosts_exist');
        expect($this->analytics->roles()->where('role', RoleName::Analytics->value)->exists())->toBeTrue();
    });

    it('refuses to remove a Project instance that still publishes a tracking host', function (): void {
        instance_analytics_tracking($this->instance);

        expect(fn () => app(DestroyInstanceAction::class)->execute($this->instance->refresh(), force: true))
            ->toThrow(function (ResourceOperationException $exception): void {
                expect($exception->errorCode)->toBe('analytics.tracking_hosts_exist');
            });
        expect(RouteAnalyticsTracking::query()->count())->toBe(1);
    });
});

describe('analytics native participant boundary', function (): void {
    beforeEach(function (): void {
        $this->operation = analytics_intent_start($this);
        $this->path = '/api/v1/direct-operations/'.$this->operation->id;
        config(['dependency_detection.services_node_id' => $this->analytics->id,
            'operation_executor.worker_node_ids' => [$this->router->id, $this->ingress->id, $this->workload->id]]);
        foreach ([$this->analytics, $this->router, $this->ingress, $this->workload] as $node) {
            $node->accessibleNodes()->attach($this->gateway);
        }
        $scope = new SettingScope(SettingScopeType::Gateway);
        $settings = app(SettingRepository::class);
        foreach (['analytics.management_email' => 'native@example.test', 'analytics.management_password' => 'private-management-sentinel'] as $key => $value) {
            $settings->put($scope, $key, $value, SettingValueProtection::Secret);
        }
        app(AnalyticsStatsKeyStore::class)->put('private-stats-sentinel');
    });

    it('returns a fresh caller-scoped public view with truthful parent identity and no parent result', function (): void {
        $this->withServerVariables(['REMOTE_ADDR' => $this->router->wireguard_ip]);
        $query = '?run_id=a075eb23-7ac4-4487-80d1-57a038a92676';
        $first = $this->getJson($this->path.$query)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.node_id', $this->analytics->id)
            ->assertJsonPath('data.input.participant_node_id', $this->router->id)
            ->assertJsonMissingPath('data.result')->assertJsonMissingPath('data.input.site')
            ->assertJsonMissingPath('data.input.plausible_credentials');
        app(AnalyticsIntentProjection::class)->apply($this->operation, analytics_intent_result('running', ['certificates_ready' => true]));
        $next = $this->getJson($this->path.$query)->assertOk();
        expect($next->json('data.input.caddy_publication.caddy.content'))->not->toBe($first->json('data.input.caddy_publication.caddy.content'));
        expect($next->getContent())->not->toContain('private-management-sentinel')->not->toContain('private-stats-sentinel');
    });

    it('admits private TLS probes only on Ingress and activates its handler after verification', function (): void {
        $projection = app(AnalyticsIntentProjection::class);
        $router = $projection->publicInput($this->operation, $this->router->id);
        $before = $projection->publicInput($this->operation, $this->ingress->id);
        expect($router['public_verification'])->toBe([])
            ->and($before['certificates'])->toBe([])
            ->and($before['public_verification'][0]['probe'])->toBe([
                'address' => '10.10.0.20', 'port' => 443, 'domain' => 'analytics.shop.example.com',
            ]);
        $projection->apply($this->operation, analytics_intent_result('running', ['certificates_ready' => true]));
        $prepared = $projection->publicInput($this->operation, $this->ingress->id);
        expect($prepared['caddy_publication']['caddy']['content'])->toBe($before['caddy_publication']['caddy']['content']);
        $projection->apply($this->operation, analytics_intent_result('running', ['public_verified' => true]));
        $active = $projection->publicInput($this->operation, $this->ingress->id);
        expect($active['caddy_publication']['caddy']['content'])->not->toBe($prepared['caddy_publication']['caddy']['content'])
            ->toContain('tls force_automate');
    });

    it('exposes the canonical private DNS payload only to its admitted owner', function (): void {
        $dns = instance_analytics_node('private-dns', '10.44.0.82', null, RoleName::Vpn);
        $operation = analytics_intent_start($this);

        $view = app(AnalyticsIntentProjection::class)->publicInput($operation, $dns->id);

        expect($view)->toHaveKey('private_dns_publication')->not->toHaveKey('dns_publication')
            ->and($view['private_dns_publication']['node_id'])->toBe($dns->id)
            ->and($view['private_dns_publication']['digest'])->toBe(hash('sha256', $view['private_dns_publication']['catalog']));
    });

    it('refuses fresh participant reads after admitted ownership or placement changes', function (string $change): void {
        if ($change === 'owner') {
            $this->appRoute->update(['publication' => RoutePublication::Private]);
        } else {
            Route::query()->where('kind', RouteKind::AnalyticsTracking)->sole()
                ->update(['publication' => RoutePublication::Private]);
        }
        $this->withServerVariables(['REMOTE_ADDR' => $this->router->wireguard_ip]);

        $this->getJson($this->path.'?run_id=a075eb23-7ac4-4487-80d1-57a038a92676')
            ->assertConflict()->assertJsonPath('error.code', 'analytics.tracking_changed');

        expect($this->operation->fresh()->status)->toBe(OperationStatus::Running);
    })->with(['owner', 'tracking']);

    it('never grants a public participant POST or private authority', function (): void {
        $this->withServerVariables(['REMOTE_ADDR' => $this->router->wireguard_ip]);
        $this->postJson($this->path.'/result', analytics_intent_result('succeeded', analytics_intent_success()))->assertForbidden();
        $this->postJson($this->path.'/primitive', ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676'])->assertForbidden();
        $this->getJson($this->path.'/private-material?run_id=a075eb23-7ac4-4487-80d1-57a038a92676')->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
    });

    it('refuses unadmitted Nodes, foreign runs, unfinished binding and terminal public reads', function (): void {
        $query = '?run_id=a075eb23-7ac4-4487-80d1-57a038a92676';
        $this->withServerVariables(['REMOTE_ADDR' => $this->workload->wireguard_ip]);
        $this->getJson($this->path.$query)->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => $this->router->wireguard_ip]);
        $this->getJson($this->path.'?run_id=20e8138a-cf7f-4c6c-85fd-ff561b575ac8')->assertConflict()->assertJsonPath('error.code', 'operation.run_mismatch');
        $state = $this->operation->state;
        unset($state['swamp_run_id']);
        $this->operation->update(['state' => $state]);
        $this->getJson($this->path.$query)->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');
        $this->operation->update(['status' => OperationStatus::Succeeded]);
        $this->getJson($this->path.$query)->assertConflict()->assertJsonPath('error.code', 'operation.finished');
    });

    it('hydrates protected credentials only for the root Node and current exact unfinished run', function (): void {
        $this->withServerVariables(['REMOTE_ADDR' => $this->analytics->wireguard_ip]);
        $path = $this->path.'/private-material?run_id=a075eb23-7ac4-4487-80d1-57a038a92676';
        $this->getJson($path)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.plausible_credentials.password', 'private-management-sentinel')
            ->assertJsonPath('data.plausible_credentials.apiKey', 'private-stats-sentinel');
        $this->getJson($this->path.'?run_id=a075eb23-7ac4-4487-80d1-57a038a92676')->assertOk()->assertDontSee('private-management-sentinel');
        $this->analytics->roles()->update(['status' => LifecycleStatus::Provisioning]);
        $this->getJson($path)->assertConflict();
        $this->analytics->roles()->update(['status' => LifecycleStatus::Active]);
        $this->operation->update(['status' => OperationStatus::Succeeded]);
        $this->getJson($path)->assertConflict()->assertJsonPath('error.code', 'operation.finished');
        expect(json_encode([$this->operation->fresh()->toArray(), Activity::query()->get()->toArray()]))
            ->not->toContain('private-management-sentinel')->not->toContain('private-stats-sentinel');
    });

    it('keeps certificate signing and public request storage in Swamp', function (): void {
        $this->withServerVariables(['REMOTE_ADDR' => $this->analytics->wireguard_ip]);
        $this->postJson($this->path.'/primitive', ['run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
        $this->postJson($this->path.'/result', analytics_intent_result('running', ['certificate_requests' => [
            ['node_id' => $this->router->id, 'scope' => 'route-public', 'request_base64' => base64_encode('public-csr')],
        ]]))->assertOk();
        expect($this->operation->fresh()->state)->not->toHaveKey('certificate_requests');
    });

});

it('keeps the root lifecycle readable after its own publication ownership changes', function (): void {
    $this->analytics->update(['cluster_id' => $this->cluster->id, 'lan_ip' => '10.10.0.40']);
    $this->router->roles()->where('role', RoleName::Router)->update(['node_id' => $this->analytics->id]);
    $operation = analytics_intent_start($this);
    config(['dependency_detection.services_node_id' => $this->analytics->id]);
    $this->analytics->accessibleNodes()->attach($this->gateway);
    $this->withServerVariables(['REMOTE_ADDR' => $this->analytics->wireguard_ip]);
    $this->appRoute->update(['publication' => RoutePublication::Private]);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->getJson($path.'?run_id=a075eb23-7ac4-4487-80d1-57a038a92676')->assertOk()
        ->assertJsonPath('data.status', 'running')->assertJsonMissingPath('data.input.caddy_publication');
    expect(fn () => app(AnalyticsIntentProjection::class)->publicInput($operation, $this->analytics->id))->toThrow(ResourceOperationException::class);
    $this->postJson($path.'/result', analytics_intent_result('failed', ['error_code' => 'analytics.change_failed'], 'analytics.change_failed'))
        ->assertOk()->assertJsonPath('data.status', 'failed');
});

<?php

declare(strict_types=1);

use App\Actions\Routes\AdmitRouteOperationAction;
use App\Actions\Routes\AwaitRouteOperationAction;
use App\Actions\Routes\RouteChildAdmission;
use App\Actions\Routes\RouteMutationState;
use App\Actions\Routes\RouteTargetState;
use App\Data\Routes\CreateRouteData;
use App\Data\Routes\RouteTargetDispositionData;
use App\Data\Routes\SetRouteTargetsData;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Instances\InstanceState;
use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\AnalyticsRouteParentCommand;
use App\Domain\Routes\AnalyticsRouteParentExecution;
use App\Domain\Routes\ClusterRouterParentCommand;
use App\Domain\Routes\ClusterRouterParentExecution;
use App\Domain\Routes\GeneratedRouteUpdateOwnership;
use App\Domain\Routes\PreparePrivateDnsCommand;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteNodeEffectCommand;
use App\Domain\Routes\RouteParentCommand;
use App\Domain\Routes\RouteParentExecution;
use App\Domain\Routes\RouteProjectionPlan;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use App\Models\RouteRemovalResidue;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAgentCommandRunner;

beforeEach(function (): void {
    $this->node = Node::query()->create(['name' => 'dev', 'public_ssh_host' => '192.0.2.3', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.3', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $project = Project::query()->create(['name' => 'Proof', 'slug' => 'proof', 'repository_url' => 'https://example.test/proof.git', 'root' => 'public']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'private', 'checkout_path' => '/srv/proof/private', 'branch' => 'main', 'starting_commit' => str_repeat('a', 40), 'source_is_laravel' => false, 'status' => InstanceState::SourceResolved]);
    $this->route = Route::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'domain' => 'private.test', 'provenance' => 'explicit', 'publication' => RoutePublication::Private, 'status' => RouteStatus::Pending]);
    $this->route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    });
    app()->instance(LeafCertificateSigner::class, new class implements LeafCertificateSigner
    {
        public function rootCertificate(): string
        {
            return 'public-root';
        }

        public function sign(string $hostname, string $certificateRequest): string
        {
            throw new LogicException('Planning must not sign.');
        }
    });
    $this->agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $this->agents);
    $this->operation = new Operation(['id' => 17, 'kind' => 'route.converge', 'node_id' => $this->node->id, 'target_type' => 'route', 'target_id' => $this->route->id, 'input' => ['route_id' => $this->route->id, 'instance_id' => $this->instance->id]]);
});

it('captures deterministic Node-bound children from stored development placement without executing them', function (): void {
    $first = app(RouteProjectionPlan::class)->converge($this->operation, $this->route, $this->instance);
    $second = app(RouteProjectionPlan::class)->converge($this->operation, $this->route, $this->instance);
    expect($first)->toBe($second)->and($first['placements'])->toBe([['instance_id' => $this->instance->id, 'node_id' => $this->node->id, 'production' => false]])
        ->and($first['stages']['workload-certificate'][0]['kind'])->toBe('certificate.converge')
        ->and($first['stages']['workload-prepared'][0]['input'])->toBe(['route_id' => $this->route->id, 'instance_id' => $this->instance->id])
        ->and($this->agents->calls)->toBe([]);
});

it('uses the production PHP child and exact URL-only key for a pending web-root Route', function (): void {
    $this->node->roles()->delete();
    $this->node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $this->instance->update(['environment' => 'production', 'production_user' => 'orbit-proof', 'production_home' => '/home/orbit-proof', 'checkout_path' => '/home/orbit-proof/releases/initial']);
    $this->route->update(['web_root' => 'apps/docs/public']);
    $plan = app(RouteProjectionPlan::class)->converge($this->operation, $this->route->fresh(), $this->instance->fresh());
    expect($plan['placements'][0]['production'])->toBeTrue()
        ->and($plan['stages'])->not->toHaveKey('workload-prepared')
        ->and($plan['stages']['production-runtime'][0]['kind'])->toBe('instance.prepare_php_runtime')
        ->and($plan['stages']['production-runtime'][0]['input'])->toBe(['instance_id' => $this->instance->id])
        ->and($plan['stages']['synchronize-web-root-urls'][0]['key'])->toBe('synchronize-web-root-urls.'.$this->instance->id)
        ->and($plan['stages']['synchronize-web-root-urls'][0]['input'])->toBe(['instance_id' => $this->instance->id, 'route_domain' => 'authoritative']);
});

it('prepares analytics host certificates on the recorded serving Node without publishing early', function (): void {
    $tracking = Route::query()->create(['kind' => RouteKind::AnalyticsTracking, 'node_id' => $this->node->id, 'domain' => 'tracking.private.test', 'provenance' => 'explicit', 'publication' => RoutePublication::Private, 'status' => RouteStatus::Pending]);
    $tracking->analyticsTracking()->create(['instance_id' => $this->instance->id]);
    $this->operation->kind = 'route.analytics_host_prepare';
    $plan = app(RouteProjectionPlan::class)->analytics($this->operation, $tracking);
    expect(array_keys($plan['stages']))->toBe(['host-certificate'])
        ->and($plan['stages']['host-certificate'][0]['node_id'])->toBe($this->node->id)
        ->and($plan['stages']['host-certificate'][0]['input']['scope'])->toBe('route-'.$tracking->id.'-router')
        ->and($tracking->fresh()->sites_published)->toBeFalse()->and($this->agents->calls)->toBe([]);
    $this->operation->node_id = $this->node->id + 1;
    expect(fn () => app(RouteProjectionPlan::class)->analytics($this->operation, $tracking))->toThrow(ResourceOperationException::class);
});

it('keeps replacement environment ownership and certificate cleanup in separate proven stages', function (): void {
    $candidate = app(RouteMutationState::class)->reserve($this->route, 'next.private.test');
    $plan = app(RouteProjectionPlan::class)->replacement($this->operation, $this->route->fresh(), $candidate);
    expect($plan['route_id'])->toBe($this->route->id)->and($plan['candidate_route_id'])->toBe($candidate->id)
        ->and($plan['stages']['workload-certificate'][0]['input']['scope'])->toBe('app-instance-'.$this->instance->id.'-hostname-change')
        ->and($plan['stages']['laravel-url'][0]['input'])->toBe(['instance_id' => $this->instance->id, 'route_domain' => 'candidate'])
        ->and($plan['stages']['rollback-environment'][0]['input'])->toBe(['instance_id' => $this->instance->id, 'route_domain' => 'authoritative'])
        ->and($plan['stages']['cleanup-caddy'][0]['kind'])->toBe('route.caddy_converge')
        ->and($plan['stages']['cleanup-certificates'][0]['kind'])->toBe('certificate.remove')
        ->and($this->agents->calls)->toBe([]);
});

it('refuses unavailable composition before reserving a Route or falling back to a PHP executor', function (): void {
    expect(fn () => app(AdmitRouteOperationAction::class)->create(new CreateRouteData(
        'unavailable.private.test', RoutePublication::Private, instanceId: $this->instance->id,
    )))->toThrow(ResourceOperationException::class, 'shared child and result contracts');
    expect(Route::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(0)->and($this->agents->calls)->toBe([]);
});

it('admits a destroy child through the normal Route action only when composition is available', function (): void {
    $parent = new Operation(['kind' => 'analytics.instance_enable', 'target_type' => 'instance', 'target_id' => $this->instance->id]);
    $intent = ['key' => 'remove-old-route', 'kind' => 'route.destroy', 'node_id' => $this->node->id,
        'input' => ['route_id' => $this->route->id, 'offline' => false]];
    expect(fn () => app(RouteChildAdmission::class)->admit($parent, $intent))
        ->toThrow(ResourceOperationException::class, 'shared child and result contracts');
    expect(Route::query()->find($this->route->id))->not->toBeNull()->and(Operation::query()->count())->toBe(0);
});

it('exposes only the active analytics child phase and refuses unproven publication', function (): void {
    $tracking = Route::query()->create(['kind' => RouteKind::AnalyticsTracking, 'node_id' => $this->node->id, 'domain' => 'tracking.private.test', 'provenance' => 'explicit', 'publication' => RoutePublication::Private, 'status' => RouteStatus::Pending]);
    $tracking->analyticsTracking()->create(['instance_id' => $this->instance->id]);
    $parent = Operation::query()->create(['kind' => 'route.analytics_publish', 'target_type' => 'route', 'target_id' => $tracking->id, 'node_id' => $this->node->id, 'input' => ['route_id' => $tracking->id], 'status' => OperationStatus::Queued, 'sequence' => 1]);
    $command = app(AnalyticsRouteParentCommand::class);
    $prepared = $command->run($parent, ['action' => 'prepare']);
    expect(array_column($prepared['child_phases'], 'key'))->toContain('host-certificate.node-'.$this->node->id.'-route-'.$tracking->id.'-router');
    $execution = app(AnalyticsRouteParentExecution::class);
    expect($execution->childContext($parent)['children'])->toBe([]);
    $command->run($parent, ['action' => 'open', 'stage' => 'host-certificate']);
    expect(array_column($execution->childContext($parent->fresh())['children'], 'kind'))->toBe(['certificate.converge']);
    expect(fn () => $command->run($parent, ['action' => 'open', 'stage' => 'publish-sites']))
        ->toThrow(ResourceOperationException::class, 'preceding analytics Route phase');
    expect($tracking->fresh()->sites_published)->toBeFalse()->and($this->agents->calls)->toBe([]);
});

it('withdraws analytics site intent before Caddy and keeps its certificate phase unavailable', function (): void {
    $tracking = Route::query()->create(['kind' => RouteKind::AnalyticsTracking, 'node_id' => $this->node->id, 'domain' => 'tracking.private.test', 'provenance' => 'explicit', 'publication' => RoutePublication::Private, 'status' => RouteStatus::Pending]);
    $tracking->analyticsTracking()->create(['instance_id' => $this->instance->id]);
    $tracking->publishSites();
    $parent = Operation::query()->create(['kind' => 'route.analytics_remove', 'target_type' => 'route', 'target_id' => $tracking->id, 'node_id' => $this->node->id, 'input' => ['route_id' => $tracking->id], 'status' => OperationStatus::Queued, 'sequence' => 1]);
    $command = app(AnalyticsRouteParentCommand::class);
    $command->run($parent, ['action' => 'prepare']);
    $command->run($parent, ['action' => 'open', 'stage' => 'dns']);
    $command->run($parent, ['action' => 'commit', 'stage' => 'dns']);
    $command->run($parent, ['action' => 'open', 'stage' => 'caddy']);
    expect($tracking->fresh()->sites_published)->toBeFalse()->and($tracking->fresh()->status)->toBe(RouteStatus::Retiring);
    expect(array_column(app(AnalyticsRouteParentExecution::class)->childContext($parent->fresh())['children'], 'kind'))->toBe(['route.caddy_converge']);
    expect(fn () => $command->run($parent, ['action' => 'open', 'stage' => 'certificates']))
        ->toThrow(ResourceOperationException::class, 'preceding analytics Route phase');
    expect($this->agents->calls)->toBe([]);
});

it('preserves withdrawn Route rows and targets while recording offline cleanup residue', function (string $kind): void {
    app()->instance(NodeReachabilityProbe::class, new class implements NodeReachabilityProbe
    {
        public function degradation(Node $node): ?ExporterDegradationReason
        {
            return ExporterDegradationReason::Unreachable;
        }
    });
    $this->route->update(['sites_published' => true, 'status' => RouteStatus::Active]);
    $operation = Operation::query()->create(['kind' => $kind, 'target_type' => 'route', 'target_id' => $this->route->id,
        'node_id' => $this->node->id, 'input' => ['route_id' => $this->route->id, 'offline' => true], 'status' => 'queued', 'sequence' => 1]);
    $command = app(RouteParentCommand::class);
    $command->run($operation, ['action' => 'prepare']);
    foreach (app(RouteParentExecution::class)->sequence($operation) as $stage) {
        $command->run($operation, ['action' => 'open', 'stage' => $stage]);
        $command->run($operation, ['action' => 'commit', 'stage' => $stage]);
    }
    $proof = $command->run($operation, ['action' => 'prove']);
    expect($proof['retained_on_nodes'][0]['node_id'])->toBe($this->node->id)
        ->and($proof['retained_on_nodes'][0]['steps'])->toContain('caddy', 'php', 'firewall')
        ->and(RouteRemovalResidue::query()->sole()->route_id)->toBe($this->route->id)
        ->and(Route::query()->whereKey($this->route->id)->exists())->toBe($kind === 'route.withdraw')
        ->and($this->route->targets()->exists())->toBe($kind === 'route.withdraw')
        ->and($this->agents->calls)->toBe([]);
})->with(['withdraw keeps ownership' => 'route.withdraw', 'destroy deletes on proof' => 'route.destroy']);

it('withdraws an offline unactivated replacement without deleting lifecycle-owned rows', function (): void {
    app()->instance(NodeReachabilityProbe::class, new class implements NodeReachabilityProbe
    {
        public function degradation(Node $node): ?ExporterDegradationReason
        {
            return ExporterDegradationReason::Unreachable;
        }
    });
    $candidate = app(RouteMutationState::class)->reserve($this->route, 'unused.private.test');
    $operation = Operation::query()->create(['kind' => 'route.withdraw', 'target_type' => 'route', 'target_id' => $candidate->id,
        'node_id' => $this->node->id, 'input' => ['route_id' => $candidate->id, 'offline' => true], 'status' => 'queued', 'sequence' => 1]);
    $command = app(RouteParentCommand::class);
    $command->run($operation, ['action' => 'prepare']);
    foreach (app(RouteParentExecution::class)->sequence($operation) as $stage) {
        $command->run($operation, ['action' => 'open', 'stage' => $stage]);
        $command->run($operation, ['action' => 'commit', 'stage' => $stage]);
    }
    expect($candidate->fresh()->status)->toBe(RouteStatus::Failed)->and($candidate->targets()->exists())->toBeTrue()
        ->and($this->route->fresh()->replaced_by_route_id)->toBe($candidate->id)
        ->and(RouteRemovalResidue::query()->sole()->route_id)->toBe($candidate->id)
        ->and($this->route->fresh()->status)->toBe(RouteStatus::Pending);
});

it('stores a web-root change only at its admitted phase and binds distinct URL-only rollback children', function (): void {
    $this->route->update(['status' => RouteStatus::Active]);
    $this->instance->update(['status' => InstanceState::Active]);
    $this->route = Route::query()->create(['project_id' => $this->instance->project_id, 'node_id' => $this->node->id, 'domain' => 'docs.private.test',
        'provenance' => 'explicit', 'publication' => RoutePublication::Private, 'status' => RouteStatus::Pending, 'web_root' => 'docs/public']);
    $this->route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $this->route->update(['status' => RouteStatus::Active]);
    $operation = Operation::query()->create(['kind' => 'route.update', 'target_type' => 'route', 'target_id' => $this->route->id,
        'node_id' => $this->node->id, 'input' => ['route_id' => $this->route->id, 'mode' => 'web_root', 'web_root' => 'help/public'], 'status' => 'queued', 'sequence' => 1]);
    $command = app(RouteParentCommand::class);
    $plan = $command->run($operation, ['action' => 'prepare']);
    expect($this->route->fresh()->web_root)->toBe('docs/public')
        ->and(array_column($plan['child_phases'], 'key'))->toContain('synchronize-web-root-urls.'.$this->instance->id, 'synchronize-web-root-urls.rollback.'.$this->instance->id);
    $command->run($operation, ['action' => 'open', 'stage' => 'web-root-stored']);
    $command->run($operation, ['action' => 'commit', 'stage' => 'web-root-stored']);
    expect($this->route->fresh()->web_root)->toBe('help/public');
    expect(fn () => $command->run($operation, ['action' => 'open', 'stage' => 'workload-caddy']))
        ->toThrow(ResourceOperationException::class, 'preceding Route phase');
    expect($this->agents->calls)->toBe([]);
});

it('omits Metrics without an active assignment and captures only its Node and empty admission input', function (): void {
    $plans = app(RouteProjectionPlan::class);
    $plan = $plans->withMetrics(['stages' => []]);
    expect($plan['stages'])->toBe([]);
    $this->node->roles()->create(['role' => RoleName::Metrics, 'status' => LifecycleStatus::Active]);
    $plan = $plans->withMetrics(['stages' => []]);
    expect($plan['stages']['metrics-reconciled'])->toBe([['key' => 'metrics-reconciled.fleet', 'kind' => 'metrics.reconcile', 'node_id' => $this->node->id, 'input' => []]])
        ->and(Operation::query()->count())->toBe(0)->and($this->agents->calls)->toBe([]);
});

it('binds Router baselines to owned Node children and forbids premature cutover', function (): void {
    $cluster = Cluster::query()->create(['name' => 'Router proof']);
    $this->node->update(['cluster_id' => $cluster->id]);
    $old = Node::query()->create(['name' => 'old-router', 'public_ssh_host' => '192.0.2.5', 'user' => 'orbit', 'platform' => 'linux', 'cluster_id' => $cluster->id, 'wireguard_ip' => '10.44.0.5', 'status' => LifecycleStatus::Active]);
    $candidate = $this->node->roles()->create(['role' => RoleName::Router, 'cluster_id' => $cluster->id, 'status' => LifecycleStatus::Provisioning]);
    $oldRole = $old->roles()->create(['role' => RoleName::Router, 'cluster_id' => $cluster->id, 'status' => LifecycleStatus::Active]);
    $operation = Operation::query()->create(['kind' => 'cluster.router_set', 'target_type' => 'cluster_router', 'target_id' => $cluster->id, 'node_id' => $this->node->id,
        'input' => ['cluster_id' => $cluster->id, 'candidate_role_id' => $candidate->id, 'old_role_id' => $oldRole->id], 'status' => 'queued', 'sequence' => 1]);
    $command = app(ClusterRouterParentCommand::class);
    $command->run($operation, ['action' => 'prepare']);
    $plan = $operation->fresh()->state['route_plan'];
    expect($plan['old_role_ids'])->toBe([$oldRole->id])
        ->and($plan['stages']['baseline'][0]['input'])->toBe(['assignment_id' => $candidate->id])
        ->and($plan['stages']['remove-old-baseline'][0]['input'])->toBe(['role' => 'router', 'purge_data' => false, 'offline' => false]);
    $command->run($operation, ['action' => 'open', 'stage' => 'baseline']);
    expect(fn () => $command->run($operation, ['action' => 'commit', 'stage' => 'baseline']))
        ->toThrow(ResourceOperationException::class, 'no child');
    expect(fn () => $command->run($operation, ['action' => 'open', 'stage' => 'database']))
        ->toThrow(ResourceOperationException::class, 'preceding Router phase');
    expect($candidate->fresh()->status)->toBe(LifecycleStatus::Provisioning)->and($oldRole->fresh()->status)->toBe(LifecycleStatus::Active)
        ->and($this->agents->calls)->toBe([]);
});

it('renders a prospective workload only from a proven prepared parent and withdraws it on rollback', function (): void {
    $cluster = Cluster::query()->create(['name' => 'Pool preparation']);
    $router = Node::query()->create(['name' => 'pool-router', 'public_ssh_host' => '192.0.2.9', 'user' => 'orbit', 'platform' => 'linux',
        'cluster_id' => $cluster->id, 'wireguard_ip' => '10.44.0.9', 'status' => LifecycleStatus::Active]);
    $router->roles()->create(['role' => RoleName::Router, 'cluster_id' => $cluster->id, 'status' => LifecycleStatus::Active]);
    $this->node->update(['cluster_id' => $cluster->id]);
    $this->route->update(['sites_published' => true]);
    $pool = Route::query()->create(['project_id' => $this->instance->project_id, 'cluster_id' => $cluster->id, 'domain' => 'pool.private.test',
        'provenance' => 'explicit', 'publication' => RoutePublication::Private, 'status' => RouteStatus::Pending]);
    $proposal = new SetRouteTargetsData([$this->instance->id], []);
    app(RouteTargetState::class)->reserve($pool, $proposal);
    $operation = Operation::query()->create(['kind' => 'route.target_set', 'target_type' => 'route', 'target_id' => $pool->id, 'node_id' => $router->id,
        'input' => ['route_id' => $pool->id, 'mode' => 'targets', 'targets' => [$this->instance->id], 'dispositions' => [],
            'target_instance_ids' => [$this->instance->id], 'previous_target_instance_ids' => [], 'removed_instance_ids' => [],
            'instance_placements' => [['instance_id' => $this->instance->id, 'node_id' => $this->node->id, 'production' => false]]], 'status' => 'queued', 'sequence' => 1]);
    app(RouteParentCommand::class)->run($operation, ['action' => 'prepare']);
    $sites = app(DevelopmentSiteRepository::class);
    expect($sites->forNode($this->node)->where('domain', $pool->domain))->toHaveCount(0);
    $operation->update(['state' => [...$operation->state, 'route_completed_stages' => ['target-workload-certificates' => true]]]);
    $prepared = $sites->forNode($this->node)->firstWhere('domain', $pool->domain);
    expect($prepared?->certificateScope)->toBe('app-instance-'.$this->instance->id.'-hostname-change')
        ->and($this->route->targets()->exists())->toBeTrue()->and($pool->targets()->exists())->toBeFalse();
    $operation->update(['state' => [...$operation->state, 'route_branch' => 'rollback_targets']]);
    expect($sites->forNode($this->node)->where('domain', $pool->domain))->toHaveCount(0)
        ->and($sites->forNode($this->node)->where('domain', $this->route->domain))->toHaveCount(1)
        ->and($this->agents->calls)->toBe([]);
});

it('refuses a forged successful parent result even with a genuine final proof receipt', function (): void {
    $this->operation->state = ['executor_runs' => ['route-proof' => ['result' => ['ready' => true, 'route_id' => $this->route->id, 'retained_on_nodes' => []]]]];
    $execution = app(RouteParentExecution::class);
    expect(fn () => $execution->assertCompletion($this->operation, OperationOutcome::succeeded([
        'ready' => true, 'route_id' => $this->route->id + 1, 'retained_on_nodes' => [],
    ])))->toThrow(ResourceOperationException::class, 'final state proof');
    $execution->assertCompletion($this->operation, OperationOutcome::succeeded([
        'ready' => true, 'route_id' => $this->route->id, 'retained_on_nodes' => [],
    ]));
});

it('retained Gateway callers return only the exact successful Route Operation and preserve failure evidence', function (): void {
    $operation = Operation::query()->create(['kind' => 'route.update', 'target_type' => 'route', 'target_id' => $this->route->id,
        'node_id' => $this->node->id, 'input' => ['route_id' => $this->route->id], 'status' => 'succeeded', 'sequence' => 1,
        'result' => ['ready' => true, 'route_id' => $this->route->id], 'finished_at' => now()]);
    // A retained caller waits only after admission commits, including the test harness transaction.
    while (DB::transactionLevel() > 0) {
        DB::commit();
    }
    $await = app(AwaitRouteOperationAction::class);
    expect($await->route($operation)->id)->toBe($this->route->id);
    $operation->update(['status' => 'failed', 'error_code' => 'route.workload_failed']);
    expect(fn () => $await->execute($operation))->toThrow(ResourceOperationException::class, 'did not succeed');
    expect($operation->fresh()->error_code)->toBe('route.workload_failed')->and($this->agents->calls)->toBe([]);
});

it('recovers an already cut-over replacement from publication or cleanup without replaying database cutover', function (string $checkpoint, string $mode): void {
    $this->route->update(['status' => RouteStatus::Active]);
    $candidate = app(RouteMutationState::class)->reserve($this->route, 'continued.private.test');
    app(RouteMutationState::class)->cutover($this->route, $candidate);
    $candidate->update(['replacement_step' => RouteReplacementStep::from($checkpoint)]);
    $operation = Operation::query()->create(['kind' => 'route.update', 'target_type' => 'route', 'target_id' => $this->route->id,
        'node_id' => $this->node->id, 'input' => ['route_id' => $this->route->id, 'mode' => 'replace_domain', 'candidate_route_id' => $candidate->id],
        'status' => 'queued', 'sequence' => 1]);
    $prepared = app(RouteParentCommand::class)->run($operation, ['action' => 'prepare']);
    expect($prepared['mode'])->toBe($mode)
        ->and(app(RouteParentExecution::class)->sequence($operation))->not->toContain('database-cutover', 'workload-certificate')
        ->and($this->route->fresh()->status)->toBe(RouteStatus::Retiring)
        ->and($candidate->fresh()->status)->toBe(RouteStatus::Activating)->and($this->agents->calls)->toBe([]);
})->with(['after cutover' => ['database-cutover', 'replacement_after_cutover'], 'during cleanup' => ['cleanup', 'replacement_cleanup']]);

it('recovers a committed target pool from stored database evidence without repeating prospective preparation', function (): void {
    $this->node->roles()->delete();
    $this->node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $this->instance->update(['environment' => 'production', 'production_user' => 'orbit-proof', 'production_home' => '/home/orbit-proof', 'checkout_path' => '/home/orbit-proof/releases/initial']);
    $proposal = new SetRouteTargetsData([$this->instance->id], []);
    $source = Operation::query()->create(['kind' => 'route.target_set', 'target_type' => 'route', 'target_id' => $this->route->id, 'node_id' => $this->node->id,
        'input' => ['route_id' => $this->route->id, 'mode' => 'targets', 'targets' => [$this->instance->id], 'dispositions' => [],
            'target_instance_ids' => [$this->instance->id], 'previous_target_instance_ids' => [$this->instance->id], 'removed_instance_ids' => [],
            'instance_placements' => [['instance_id' => $this->instance->id, 'node_id' => $this->node->id, 'production' => true]]],
        'status' => 'failed', 'error_code' => 'caddy.publish_failed', 'sequence' => 1]);
    $plan = app(RouteProjectionPlan::class)->targets($source, $this->route, $proposal);
    $source->update(['state' => ['route_plan' => $plan, 'executor_runs' => ['stage-commit.database-committed' => ['result' => ['ready' => true]]]]]);
    $this->route->update(['target_set_intent' => [...$proposal->toIntent(), 'preparation_operation_id' => $source->id], 'target_set_step' => 'database-committed']);
    $next = Operation::query()->create(['kind' => 'route.target_set', 'target_type' => 'route', 'target_id' => $this->route->id, 'node_id' => $this->node->id,
        'input' => [...$source->input, 'mode' => 'targets_recover', 'recovery_operation_id' => $source->id], 'status' => 'queued', 'sequence' => 2]);
    $command = app(RouteParentCommand::class);
    expect($command->run($next, ['action' => 'prepare'])['mode'])->toBe('targets_recover')
        ->and(app(RouteParentExecution::class)->sequence($next))->not->toContain('target-workload-certificates', 'target-runtime-prepared');
    $command->run($next, ['action' => 'open', 'stage' => 'database-committed']);
    $command->run($next, ['action' => 'commit', 'stage' => 'database-committed']);
    expect($this->route->targets()->pluck('instance_id')->all())->toBe([$this->instance->id])
        ->and($this->route->fresh()->target_set_step)->toBe('database-committed')->and($this->agents->calls)->toBe([]);
});

it('refuses successful completion after rollback and preserves failed creation phase on the Route', function (): void {
    $operation = Operation::query()->create(['kind' => 'route.converge', 'target_type' => 'route', 'target_id' => $this->route->id,
        'node_id' => $this->node->id, 'input' => ['route_id' => $this->route->id], 'status' => 'running', 'sequence' => 1,
        'state' => ['route_plan' => ['mode' => 'converge', 'route_id' => $this->route->id], 'route_active_stage' => 'workload-caddy']]);
    $execution = app(RouteParentExecution::class);
    $execution->assertCompletion($operation, OperationOutcome::failed('caddy.publish_failed'));
    expect($this->route->fresh()->status)->toBe(RouteStatus::Failed)
        ->and($this->route->fresh()->failed_step)->toBe('workload-caddy')
        ->and($this->route->fresh()->error_code)->toBe('caddy.publish_failed');

    $proof = ['ready' => true, 'route_id' => $this->route->id, 'retained_on_nodes' => []];
    $operation->update(['state' => [...$operation->state, 'route_branch' => 'rollback', 'executor_runs' => ['route-proof' => ['result' => $proof]]]]);
    expect(fn () => $execution->assertCompletion($operation, OperationOutcome::succeeded($proof)))
        ->toThrow(ResourceOperationException::class, 'final state proof');
    expect($this->agents->calls)->toBe([]);
});

it('keeps the original database proof until a recovery attempt commits its own phase', function (): void {
    $proposal = new SetRouteTargetsData([$this->instance->id], []);
    $plan = ['route_id' => $this->route->id, 'publication' => 'private', 'placements' => [], 'stages' => [], 'vacated_route_ids' => []];
    $source = Operation::query()->create(['kind' => 'route.target_set', 'target_type' => 'route', 'target_id' => $this->route->id,
        'node_id' => $this->node->id, 'input' => ['route_id' => $this->route->id], 'status' => 'failed', 'sequence' => 1,
        'state' => ['route_plan' => $plan, 'executor_runs' => ['stage-commit.database-committed' => ['result' => ['ready' => true]]]]]);
    $this->route->update(['target_set_intent' => [...$proposal->toIntent(), 'preparation_operation_id' => $source->id], 'target_set_step' => 'database-committed']);
    $retry = Operation::query()->create(['kind' => 'route.target_set', 'target_type' => 'route', 'target_id' => $this->route->id,
        'node_id' => $this->node->id, 'status' => 'running', 'sequence' => 2,
        'input' => ['route_id' => $this->route->id, 'mode' => 'targets_recover', 'recovery_operation_id' => $source->id,
            'targets' => [$this->instance->id], 'dispositions' => []]]);

    app(RouteParentCommand::class)->run($retry, ['action' => 'prepare']);
    $retry->update(['status' => 'failed']);
    expect(app(RouteTargetState::class)->recovery($this->route->fresh())->id)->toBe($source->id)
        ->and($this->route->fresh()->target_set_intent['preparation_operation_id'])->toBe($source->id)
        ->and($this->agents->calls)->toBe([]);
});

it('refuses Router cleanup until the recorded DNS grace period elapses', function (): void {
    $this->freezeTime();
    $operation = Operation::query()->create(['kind' => 'cluster.router_set', 'target_type' => 'cluster_router', 'target_id' => 42,
        'node_id' => $this->node->id, 'input' => [], 'status' => 'running', 'sequence' => 1,
        'state' => ['route_plan' => ['mode' => 'router_set', 'stages' => [], 'candidate_role_id' => null, 'old_role_ids' => []], 'router_dns_moved_at' => now()->toIso8601String(),
            'route_completed_stages' => array_fill_keys(['baseline', 'router-certificate', 'firewall-policy', 'workload-verify', 'router-caddy', 'dns-publication', 'database', 'prune-dns'], true)]]);
    $command = app(ClusterRouterParentCommand::class);
    expect($command->run($operation, ['action' => 'open', 'stage' => 'await-old-dns-expiry'])['wait_seconds'])->toBe(31);
    expect(fn () => $command->run($operation, ['action' => 'commit', 'stage' => 'await-old-dns-expiry']))
        ->toThrow(ResourceOperationException::class, 'DNS grace period');
    expect(fn () => $command->run($operation, ['action' => 'open', 'stage' => 'withdraw-old-sites']))
        ->toThrow(ResourceOperationException::class, 'preceding Router phase');

    $this->travel(31)->seconds();
    expect($command->run($operation, ['action' => 'commit', 'stage' => 'await-old-dns-expiry']))->toBe(['ready' => true, 'stage' => 'await-old-dns-expiry']);
    expect($command->run($operation, ['action' => 'open', 'stage' => 'withdraw-old-sites'])['opened'])->toBeTrue()
        ->and($this->agents->calls)->toBe([]);
});

it('retires only the old Router LAN rule and keeps the new Router allowance outside the removal input', function (): void {
    $cluster = Cluster::query()->create(['name' => 'Retire LAN']);
    $this->node->update(['cluster_id' => $cluster->id, 'lan_ip' => '192.0.2.3']);
    $this->route->update(['node_id' => null, 'cluster_id' => $cluster->id]);
    $old = Node::query()->create(['name' => 'retiring-router', 'public_ssh_host' => '192.0.2.5', 'user' => 'orbit', 'platform' => 'linux',
        'cluster_id' => $cluster->id, 'lan_ip' => '192.0.2.5', 'status' => LifecycleStatus::Active]);
    $old->roles()->create(['role' => RoleName::Router, 'cluster_id' => $cluster->id, 'status' => LifecycleStatus::Removing]);
    $current = Node::query()->create(['name' => 'serving-router', 'public_ssh_host' => '192.0.2.6', 'user' => 'orbit', 'platform' => 'linux',
        'cluster_id' => $cluster->id, 'lan_ip' => '192.0.2.6', 'status' => LifecycleStatus::Active]);
    $current->roles()->create(['role' => RoleName::Router, 'cluster_id' => $cluster->id, 'status' => LifecycleStatus::Active]);
    $operation = Operation::query()->create(['kind' => 'route.router_lan_retire', 'target_type' => 'route_effect', 'target_id' => $this->route->id,
        'node_id' => $this->node->id, 'input' => ['route_id' => $this->route->id, 'router_node_id' => $old->id], 'status' => 'running', 'sequence' => 1]);

    app(RouteNodeEffectCommand::class)->run($operation, ['action' => 'prepare']);
    expect($operation->fresh()->state['route_effect_plan'])->toBe(['command' => 'firewall.rules', 'input' => ['desired' => [], 'remove' => [[
        'comment' => 'orbit:route-'.$this->route->id.'-lan', 'action' => 'allow', 'direction' => 'in', 'source' => '192.0.2.5',
        'destination' => '192.0.2.3', 'port' => '443', 'protocol' => 'tcp', 'in_interface' => null, 'out_interface' => null, 'family' => 'v4',
    ]], 'enable' => false]])->and($this->agents->calls)->toBe([]);
});

it('keeps removed Instance Node and checkout snapshots after detachment and refuses placement drift', function (): void {
    $cluster = Cluster::query()->create(['name' => 'Detached snapshot']);
    $this->node->update(['cluster_id' => $cluster->id]);
    $this->node->roles()->delete();
    $this->node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $this->instance->update(['environment' => 'production']);
    $this->route->update(['node_id' => null, 'cluster_id' => $cluster->id]);
    $detachedNode = Node::query()->create(['name' => 'detached-proof', 'public_ssh_host' => '192.0.2.8', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.8', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $detachedNode->update(['cluster_id' => $cluster->id]);
    $detachedNode->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $removed = $this->instance->replicate();
    $removed->fill(['name' => 'removed', 'node_id' => $detachedNode->id, 'checkout_path' => '/srv/proof/removed']);
    $removed->save();
    $association = $this->route->targets()->create(['instance_id' => $removed->id, 'position' => 1]);
    $detached = app(RouteTargetState::class)->detachedInstances([$removed->id]);
    $proposal = new SetRouteTargetsData([$this->instance->id], [new RouteTargetDispositionData($removed->id, null, true)]);
    $placements = [['instance_id' => $this->instance->id, 'node_id' => $this->node->id, 'production' => true],
        ['instance_id' => $removed->id, 'node_id' => $detachedNode->id, 'production' => true]];
    $operation = new Operation(['id' => 51, 'kind' => 'route.target_set', 'node_id' => $this->node->id,
        'input' => ['route_id' => $this->route->id, 'target_instance_ids' => [$this->instance->id], 'previous_target_instance_ids' => [$this->instance->id, $removed->id],
            'removed_instance_ids' => [$removed->id], 'instance_placements' => $placements, 'detached_instances' => $detached]]);

    $plan = app(RouteProjectionPlan::class)->targets($operation, $this->route, $proposal);
    $association->delete();
    expect($plan['removed_instance_ids'])->toBe([$removed->id])->and($plan['placements'])->toBe($placements)
        ->and($plan['detached_instances'])->toBe([['instance_id' => $removed->id, 'node_id' => $detachedNode->id, 'checkout_path' => '/srv/proof/removed']])
        ->and($plan['stages']['removal'])->toBe([['key' => 'removal.'.$removed->id, 'kind' => 'instance.destroy', 'node_id' => $detachedNode->id,
            'input' => ['instance_id' => $removed->id, 'force' => true]]]);
    $removed->update(['checkout_path' => '/srv/proof/changed']);
    expect(fn () => app(RouteProjectionPlan::class)->targets($operation, $this->route->fresh(), $proposal))
        ->toThrow(ResourceOperationException::class, 'checkout path changed');
    expect($this->agents->calls)->toBe([]);
});

it('allows only the captured Node enrollment tuple to change a generated Route domain', function (): void {
    $generated = $this->route->replicate();
    $generated->fill(['domain' => 'generated.private.test', 'provenance' => 'generated', 'generation_basis_node_id' => $this->node->id]);
    $generated->save();
    $this->route = $generated;
    $parent = new Operation(['kind' => 'node.add', 'target_type' => 'node', 'target_id' => $this->node->id,
        'state' => ['enrollment_intent' => ['route_changes' => [['route_id' => $this->route->id, 'domain' => 'private.changed.test', 'node_id' => $this->node->id]]]]]);
    $intent = ['key' => 'route-domain.'.$this->route->id, 'kind' => 'route.update', 'node_id' => $this->node->id,
        'input' => ['route_id' => $this->route->id, 'domain' => 'private.changed.test']];

    expect(app(GeneratedRouteUpdateOwnership::class)->allows($parent, $this->route, $intent))->toBeTrue()
        ->and($this->route->fresh()->domain)->toBe('generated.private.test')->and(Operation::query()->count())->toBe(0)
        ->and($this->agents->calls)->toBe([]);
});

it('refuses generated Route enrollment authorization when its owner or exact plan changes', function (string $case): void {
    $generated = $this->route->replicate();
    $generated->fill(['domain' => 'generated.private.test', 'provenance' => 'generated', 'generation_basis_node_id' => $this->node->id]);
    $generated->save();
    $this->route = $generated;
    $change = ['route_id' => $this->route->id, 'domain' => 'private.changed.test', 'node_id' => $this->node->id];
    $parent = new Operation(['kind' => 'node.add', 'target_type' => 'node', 'target_id' => $this->node->id,
        'state' => ['enrollment_intent' => ['route_changes' => [$change]]]]);
    $intent = ['key' => 'route-domain.'.$this->route->id, 'kind' => 'route.update', 'node_id' => $this->node->id,
        'input' => ['route_id' => $this->route->id, 'domain' => 'private.changed.test']];
    if ($case === 'parent kind') {
        $parent->kind = 'node.converge';
    } elseif ($case === 'target type') {
        $parent->target_type = 'project';
    } elseif ($case === 'basis Node') {
        $parent->target_id = $this->node->id + 1;
    } elseif ($case === 'explicit Route') {
        $this->route->provenance = RouteProvenance::Explicit;
    } elseif ($case === 'changed domain') {
        $intent['input']['domain'] = 'unadmitted.test';
    } elseif ($case === 'changed child Node') {
        $intent['node_id'] = $this->node->id + 1;
    } elseif ($case === 'missing child Node') {
        $intent['node_id'] = null;
    } elseif ($case === 'changed Route') {
        $intent['input']['route_id'] = $this->route->id + 1;
    } elseif ($case === 'extra input') {
        $intent['input']['publication'] = 'public';
    } elseif ($case === 'extra plan field') {
        $parent->state = ['enrollment_intent' => ['route_changes' => [[...$change, 'allow_generated' => true]]]];
    } else {
        $parent->state = ['enrollment_intent' => ['route_changes' => []]];
    }

    expect(app(GeneratedRouteUpdateOwnership::class)->allows($parent, $this->route, $intent))->toBeFalse()
        ->and($this->route->fresh()->domain)->toBe('generated.private.test')->and($this->agents->calls)->toBe([]);
})->with(['parent kind', 'target type', 'basis Node', 'explicit Route', 'changed domain', 'changed child Node', 'missing child Node', 'changed Route', 'extra input', 'extra plan field', 'missing plan']);

it('keeps Instance rename generated-domain authorization limited to its own Route target', function (): void {
    $parent = new Operation(['kind' => 'instance.rename', 'target_type' => 'instance', 'target_id' => $this->instance->id]);
    $intent = ['key' => 'rename-route', 'kind' => 'route.update', 'node_id' => $this->node->id,
        'input' => ['route_id' => $this->route->id, 'domain' => 'renamed.private.test']];
    $ownership = app(GeneratedRouteUpdateOwnership::class);
    expect($ownership->allows($parent, $this->route, $intent))->toBeTrue();
    $parent->target_id = $this->instance->id + 1;
    expect($ownership->allows($parent, $this->route, $intent))->toBeFalse();
});

it('commits Router selection before publishing private DNS and refuses rollback afterward', function (): void {
    $cluster = Cluster::query()->create(['name' => 'Committed DNS', 'tld' => 'proof']);
    $this->node->update(['cluster_id' => $cluster->id]);
    $this->node->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);
    $old = Node::query()->create(['name' => 'dns-old-router', 'public_ssh_host' => '192.0.2.5', 'user' => 'orbit', 'platform' => 'linux', 'cluster_id' => $cluster->id, 'wireguard_ip' => '10.44.0.5', 'status' => LifecycleStatus::Active]);
    $oldRole = $old->roles()->create(['role' => RoleName::Router, 'cluster_id' => $cluster->id, 'status' => LifecycleStatus::Active]);
    $candidate = $this->node->roles()->create(['role' => RoleName::Router, 'cluster_id' => $cluster->id, 'status' => LifecycleStatus::Provisioning]);
    $this->route->update(['cluster_id' => $cluster->id, 'node_id' => null, 'status' => RouteStatus::Active]);
    $operation = Operation::query()->create(['kind' => 'cluster.router_set', 'target_type' => 'cluster_router', 'target_id' => $cluster->id,
        'node_id' => $this->node->id, 'input' => ['cluster_id' => $cluster->id, 'candidate_role_id' => $candidate->id], 'status' => OperationStatus::Queued, 'sequence' => 1]);
    $command = app(ClusterRouterParentCommand::class);
    $command->run($operation, ['action' => 'prepare']);
    $renderer = app(DevelopmentDnsConfigRenderer::class);
    expect($renderer->render())->toContain('host-record=private.test,10.44.0.5');
    // Earlier preparation phases have their Gateway-owned completion markers.
    $operation->update(['state' => [...$operation->fresh()->state, 'route_completed_stages' => array_fill_keys([
        'baseline', 'router-certificate', 'firewall-policy', 'workload-verify', 'router-caddy',
    ], true)]]);
    expect(fn () => $command->run($operation, ['action' => 'open', 'stage' => 'dns-publication']))->toThrow(ResourceOperationException::class);
    $command->run($operation, ['action' => 'open', 'stage' => 'database']);
    $command->run($operation, ['action' => 'commit', 'stage' => 'database']);
    expect($candidate->fresh()->status)->toBe(LifecycleStatus::Active)->and($oldRole->fresh()->status)->toBe(LifecycleStatus::Removing);
    $command->run($operation, ['action' => 'open', 'stage' => 'dns-publication']);
    $dns = Operation::query()->create(['kind' => 'route.private_dns_publish', 'target_type' => 'node_route_projection', 'target_id' => $this->node->id,
        'node_id' => $this->node->id, 'input' => ['reason' => 'committed Router proof'], 'status' => OperationStatus::Queued, 'sequence' => 1]);
    app(PreparePrivateDnsCommand::class)->run($dns, $dns->input);
    expect($dns->fresh()->state['private_dns_plan']['records'])->toContain('host-record=private.test,10.44.0.3')
        ->not->toContain('host-record=private.test,10.44.0.5');
    expect(fn () => $command->run($operation, ['action' => 'open', 'stage' => 'restore-dns']))->toThrow(ResourceOperationException::class, 'recovers forward');
    expect(app(ClusterRouterParentExecution::class)->sequence($operation))->toContain('database', 'dns-publication')
        ->and($this->agents->calls)->toBe([]);
});

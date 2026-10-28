<?php

declare(strict_types=1);

use App\Actions\Routes\RouteMutationState;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteKind;
use App\Domain\Routes\RouteProjectionPlan;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
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

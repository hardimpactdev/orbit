<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\NativeRouteTargetIntent;
use App\Domain\Routes\NativeRouteTargetProjection;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;

beforeEach(function (): void {
    $this->manifest = tempnam(sys_get_temp_dir(), 'target-agent-');
    file_put_contents($this->manifest, json_encode(['commit' => str_repeat('b', 40), 'version' => '0.5.0', 'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64), 'url' => 'https://example.test/agent']]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($this->manifest, sys_get_temp_dir()));
    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    });
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('public-root');
});

afterEach(function (): void {
    unlink($this->manifest);
});

/** @return array{Route,Route,Route,Instance,Instance,Node} */
function native_target_fixture(): array
{
    $cluster = Cluster::query()->create(['name' => 'target-pool']);
    $project = Project::query()->create(['name' => 'Target', 'slug' => 'target', 'repository_url' => 'https://example.test/target.git']);
    $nodes = [];
    foreach (['old', 'new', 'router'] as $index => $name) {
        $node = Node::query()->create(['name' => 'target-'.$name, 'cluster_id' => $cluster->id, 'public_ssh_host' => '192.0.2.'.($index + 1),
            'wireguard_ip' => '10.44.0.'.($index + 1), 'lan_ip' => '10.20.0.'.($index + 1), 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
        $node->roles()->create(['role' => $name === 'router' ? RoleName::Router : RoleName::AppProd, 'cluster_id' => $name === 'router' ? $cluster->id : null, 'status' => 'active']);
        $nodes[] = $node;
    }
    $routes = [];
    foreach (['pool', 'source', 'reassigned'] as $name) {
        $routes[] = Route::query()->create(['project_id' => $project->id, 'cluster_id' => $cluster->id, 'domain' => $name.'.target.test', 'publication' => 'private', 'provenance' => 'explicit', 'status' => 'pending']);
    }
    $instances = [];
    foreach (['old', 'new'] as $index => $name) {
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $nodes[$index]->id, 'name' => $name, 'environment' => 'production', 'checkout_path' => '/srv/'.$name, 'status' => 'active', 'source_is_laravel' => false]);
        $routes[$index]->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $routes[$index]->update(['status' => RouteStatus::Active]);
        $instances[] = $instance;
    }

    return [$routes[0], $routes[1], $routes[2], $instances[0], $instances[1], $nodes[2]];
}

/** @return array<string,mixed> */
function native_target_request(Instance $old, Instance $new, Route $destination): array
{
    return ['targets' => [$new->id], 'dispositions' => [['instance_id' => $old->id, 'route_id' => $destination->id, 'remove' => false]]];
}

it('freezes both associations and cleanup participants without changing Route state', function (): void {
    [$route, $source, $destination, $old, $new, $router] = native_target_fixture();

    $intent = app(NativeRouteTargetIntent::class)->forRoute($route, native_target_request($old, $new, $destination));

    expect($intent['original_associations'])->toBe([
        ['route_id' => $route->id, 'instance_id' => $old->id, 'position' => 0],
        ['route_id' => $source->id, 'instance_id' => $new->id, 'position' => 0],
    ])->and($intent['rollback_lan'])->toBe([
        ['node_id' => $new->node_id, 'route_id' => $route->id, 'operation' => 'remove'],
        ['node_id' => $old->node_id, 'route_id' => $destination->id, 'operation' => 'remove'],
    ])->and($intent['retire_lan'])->toBe([
        ['node_id' => $old->node_id, 'operation' => 'remove', 'route_id' => $route->id],
        ['node_id' => $new->node_id, 'operation' => 'remove', 'route_id' => $source->id],
    ])->and($intent['node_ids'])->toContain($old->node_id, $new->node_id, $router->id)
        ->and($route->targets()->pluck('instance_id')->all())->toBe([$old->id])
        ->and($destination->fresh()->status)->toBe(RouteStatus::Pending)
        ->and(Operation::query()->count())->toBe(0);
});

it('commits the admitted associations and restores their original owners and destination state on rollback', function (): void {
    [$route, $source, $destination, $old, $new] = native_target_fixture();
    $intent = app(NativeRouteTargetIntent::class)->forRoute($route, native_target_request($old, $new, $destination));
    $operation = Operation::query()->create(['kind' => 'route.target_set', 'target_type' => 'route', 'target_id' => $route->id,
        'node_id' => $new->node_id, 'status' => 'running', 'input' => ['route_target' => $intent], 'state' => []]);
    $projection = app(NativeRouteTargetProjection::class);

    $projection->project($operation, ['checkpoint' => 'database-committed']);
    expect($route->targets()->pluck('instance_id')->all())->toBe([$new->id])
        ->and($destination->targets()->pluck('instance_id')->all())->toBe([$old->id])
        ->and($destination->fresh()->status)->toBe(RouteStatus::Active);

    $projection->project($operation, ['checkpoint' => 'rollback-associations']);
    expect($route->targets()->pluck('instance_id')->all())->toBe([$old->id])
        ->and($source->targets()->pluck('instance_id')->all())->toBe([$new->id])
        ->and($destination->targets()->count())->toBe(0)
        ->and($destination->fresh()->status)->toBe(RouteStatus::Pending)
        ->and($destination->fresh()->sites_published)->toBeFalse()
        ->and($operation->fresh()->state['native_target_rollback'])->toBeTrue();
});

it('refuses destructive dispositions until the native Instance removal lower is composed', function (): void {
    [$route, , , $old, $new] = native_target_fixture();

    expect(fn () => app(NativeRouteTargetIntent::class)->forRoute($route, ['targets' => [$new->id], 'dispositions' => [['instance_id' => $old->id, 'remove' => true]]]))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('route.target_cleanup_unavailable'));
    expect($route->targets()->pluck('instance_id')->all())->toBe([$old->id]);
});

it('rejects a participant whose workload moved after admission', function (): void {
    [$route, , $destination, $old, $new, $router] = native_target_fixture();
    $intent = app(NativeRouteTargetIntent::class)->forRoute($route, native_target_request($old, $new, $destination));
    $operation = new Operation(['kind' => 'route.target_set', 'target_type' => 'route', 'target_id' => $route->id, 'node_id' => $new->node_id,
        'input' => ['route_target' => $intent, 'participant_node_ids' => $intent['node_ids'], 'environment_fields' => []]]);
    $new->update(['node_id' => $router->id]);

    expect(fn () => app(NativeRouteTargetProjection::class)->publicInput($operation, $old->node_id))->toThrow(ResourceOperationException::class);
    expect(fn () => app(NativeRouteTargetProjection::class)->publicInput($operation, $router->id + 100))->toThrow(ResourceOperationException::class);
});

it('records minimal native success atomically and accepts identical terminal delivery', function (): void {
    [$route, , $destination, $old, $new] = native_target_fixture();
    $existing = app(DirectOperation::class);
    $definitions = $existing->definition('route.target_set') === null ? ['route.target_set' => ['workflow' => 'operation-route-target-set', 'projection' => NativeRouteTargetProjection::class]] : [];
    $contribution = new class($definitions) implements DirectOperationContribution
    {
        public function __construct(private array $definitions) {}

        public function definitions(): array
        {
            return $this->definitions;
        }
    };
    app()->instance(DirectOperation::class, new DirectOperation([$contribution]));
    $intent = app(NativeRouteTargetIntent::class)->forRoute($route, native_target_request($old, $new, $destination));
    $run = 'b66ba314-760d-4690-b225-b62893252f54';
    $operation = Operation::query()->create(['kind' => 'route.target_set', 'target_type' => 'route', 'target_id' => $route->id,
        'node_id' => $new->node_id, 'status' => 'running', 'input' => ['route_target' => $intent], 'state' => ['swamp_run_id' => $run]]);
    $projection = app(NativeRouteTargetProjection::class);
    $data = ['run_id' => $run, 'status' => 'running', 'started_at' => '2026-10-31T09:00:00Z', 'result' => ['checkpoint' => 'database-committed']];
    $projection->apply($operation, $data);
    $terminal = [...$data, 'status' => 'succeeded', 'finished_at' => '2026-10-31T09:00:01Z', 'result' => ['ready' => true, 'route_id' => $route->id]];

    $projection->apply($operation, $terminal);
    $projection->apply($operation, $terminal);

    expect($operation->fresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($route->fresh()->target_set_intent)->toBeNull()
        ->and($route->targets()->pluck('instance_id')->all())->toBe([$new->id])
        ->and($operation->fresh()->state)->not->toHaveKeys(['steps', 'route_plan', 'route_completed_stages']);
});

it('rejects unbounded checkpoints without changing associations', function (): void {
    [$route, , $destination, $old, $new] = native_target_fixture();
    $intent = app(NativeRouteTargetIntent::class)->forRoute($route, native_target_request($old, $new, $destination));
    $operation = new Operation(['kind' => 'route.target_set', 'target_type' => 'route', 'target_id' => $route->id, 'input' => ['route_target' => $intent]]);

    expect(fn () => app(NativeRouteTargetProjection::class)->project($operation, ['checkpoint' => 'database-committed', 'arbitrary' => 'input']))->toThrow(ResourceOperationException::class);
    expect($route->targets()->pluck('instance_id')->all())->toBe([$old->id]);
});

it('renders fresh caller plans and restores original workload views before rollback publication', function (): void {
    [$route, $source, $destination, $old, $new, $router] = native_target_fixture();
    $intent = app(NativeRouteTargetIntent::class)->forRoute($route, native_target_request($old, $new, $destination));
    $operation = Operation::query()->create(['kind' => 'route.target_set', 'target_type' => 'route', 'target_id' => $route->id,
        'node_id' => $new->node_id, 'status' => 'running', 'input' => ['route_target' => $intent, 'participant_node_ids' => $intent['node_ids'], 'environment_fields' => []], 'state' => []]);
    $projection = app(NativeRouteTargetProjection::class);
    $public = $projection->publicInput($operation, $old->node_id);
    expect($public['participant_node_id'])->toBe($old->node_id)
        ->and($public['caddy_publication']['node_id'])->toBe($old->node_id)
        ->and($public['workloads'][0]['route_id'])->toBe($destination->id)
        ->and($public['firewall']['enable'])->toBeFalse()
        ->and($public)->not->toHaveKey('result');
    $projection->project($operation, ['checkpoint' => 'preparations-published']);
    $projection->project($operation, ['checkpoint' => 'database-committed']);

    $projection->project($operation, ['checkpoint' => 'rollback-associations']);
    $restored = $projection->publicInput($operation, $new->node_id);

    expect($restored['workloads'][0]['route_id'])->toBe($source->id)
        ->and($restored['workloads'][0]['instance_id'])->toBe($new->id)
        ->and($operation->fresh()->state['native_target_preparations'])->toBeFalse();
});

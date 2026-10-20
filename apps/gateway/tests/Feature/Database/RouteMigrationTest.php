<?php

declare(strict_types=1);

use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteReplacementStep;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('stores exclusive Route scope, immutable provenance, basis, and pending lifecycle', function (): void {
    expect(Schema::hasColumns('routes', [
        'project_id',
        'node_id',
        'cluster_id',
        'generation_basis_node_id',
        'domain',
        'provenance',
        'publication',
        'status',
        'failed_step',
        'error_code',
    ]))->toBeTrue();

    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
    ]);
    $node = route_migration_node('one');
    $route = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'domain' => 'acme.test',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
        'generation_basis_node_id' => $node->id,
        'status' => RouteStatus::Pending,
    ]);

    expect(fn () => $route->update(['provenance' => RouteProvenance::Explicit]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('routes')->where('id', $route->id)->update(['status' => 'active']))
        ->toThrow(QueryException::class);
});

it('rejects duplicate target Nodes', function (): void {
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
    ]);
    $nodeOne = route_migration_node('one');
    $one = route_migration_instance($project, $nodeOne, 'one', RoleName::AppDev);
    $duplicateNode = route_migration_instance($project, $nodeOne, 'duplicate', RoleName::AppDev);
    $explicit = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $nodeOne->id,
        'domain' => 'explicit.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
    ]);
    $explicit->targets()->create(['instance_id' => $one->id, 'position' => 0]);

    expect(fn () => $explicit
        ->targets()
        ->create([
            'instance_id' => $duplicateNode->id,
            'position' => 1,
        ]))->toThrow(QueryException::class)->and($explicit->targets()->count())->toBe(1);
});

it('keeps a persisted Route domain immutable', function (): void {
    $route = route_migration_replacement_route('immutable');

    expect(fn () => DB::table('routes')->where('id', $route->id)->update(['domain' => 'other.example.test']))
        ->toThrow(QueryException::class)
        ->and($route->refresh()->domain)
        ->toBe('immutable.example.test');
});

it('allows a linked replacement pair to share the same Instance targets', function (): void {
    [$current, $instance] = route_migration_replacement_pair('shared-target');
    $replacement = Route::query()->create([
        'project_id' => $current->project_id,
        'node_id' => $current->node_id,
        'domain' => 'shared-target-next.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
        'replaces_route_id' => $current->id,
        'replacement_step' => RouteReplacementStep::Reserved,
    ]);

    $replacement->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $current->update(['replaced_by_route_id' => $replacement->id]);

    expect($current->targets()->pluck('instance_id')->all())
        ->toBe([$instance->id])
        ->and($replacement->targets()->pluck('instance_id')->all())
        ->toBe([$instance->id]);
});

it('refuses a third unrelated Route targeting the same Instance', function (): void {
    [$current, $instance] = route_migration_replacement_pair('exclusive-target');
    $replacement = Route::query()->create([
        'project_id' => $current->project_id,
        'node_id' => $current->node_id,
        'domain' => 'exclusive-target-next.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
        'replaces_route_id' => $current->id,
        'replacement_step' => RouteReplacementStep::Reserved,
    ]);
    $replacement->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $unrelated = Route::query()->create([
        'project_id' => $current->project_id,
        'node_id' => $current->node_id,
        'domain' => 'exclusive-target-other.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);

    expect(fn () => $unrelated->targets()->create(['instance_id' => $instance->id, 'position' => 0]))
        ->toThrow(QueryException::class)
        ->and($unrelated->targets()->count())
        ->toBe(0);
});

it('enforces unique Route domains', function (): void {
    $first = route_migration_replacement_route('unique-owner');

    expect(fn () => Route::query()->create([
        'project_id' => $first->project_id,
        'node_id' => $first->node_id,
        'domain' => $first->domain,
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]))->toThrow(QueryException::class);
});

it('accepts pending active activating retiring and failed Route statuses', function (): void {
    $pending = route_migration_replacement_route('status-pending', activate: false);
    $active = route_migration_replacement_route('status-active');
    $activating = route_migration_replacement_route('status-activating');
    $retiring = route_migration_replacement_route('status-retiring');
    $failed = route_migration_replacement_route('status-failed');

    expect($pending->status)
        ->toBe(RouteStatus::Pending)
        ->and(fn () => $active->update(['status' => RouteStatus::Active]))
        ->not->toThrow(QueryException::class)
        ->and(fn () => $activating->update(['status' => RouteStatus::Activating]))
        ->not->toThrow(QueryException::class)
        ->and(fn () => $retiring->update(['status' => RouteStatus::Retiring]))
        ->not->toThrow(QueryException::class)
        ->and(fn () => $failed->update([
            'status' => RouteStatus::Failed,
            'failed_step' => 'workload-caddy',
            'error_code' => 'route.domain_change_failed',
        ]))
        ->not->toThrow(QueryException::class)
        ->and(fn () => $active->update([
            'failed_step' => 'dns-publication',
            'error_code' => 'route.domain_change_failed',
        ]))
        ->toThrow(QueryException::class);

    $active->refresh();

    expect(fn () => $active->update([
        'replacement_step' => RouteReplacementStep::DnsPublished,
        'failed_step' => 'dns-publication',
        'error_code' => 'route.domain_change_failed',
    ]))
        ->not->toThrow(QueryException::class)
        ->and(fn () => DB::table('routes')->insert([
            'project_id' => $pending->project_id,
            'node_id' => $pending->node_id,
            'domain' => 'status-invalid.example.test',
            'provenance' => RouteProvenance::Explicit->value,
            'publication' => RoutePublication::Private->value,
            'status' => 'unknown',
            'created_at' => now(),
            'updated_at' => now(),
        ]))
        ->toThrow(QueryException::class);
});

it('allows target-set failure evidence and empty explicit Cluster Routes', function (): void {
    $active = route_migration_replacement_route('target-set-evidence');
    $active->update(['target_set_step' => 'reserved']);

    expect(fn () => $active->update([
        'failed_step' => 'database-committed',
        'error_code' => 'route.target_set_failed',
    ]))
        ->not->toThrow(QueryException::class);

    $project = Project::query()->create([
        'name' => 'Vacated',
        'slug' => 'vacated',
        'repository_url' => 'https://example.test/vacated.git',
    ]);
    $cluster = Cluster::query()->create(['name' => 'vacated', 'state' => 'active']);
    $empty = Route::query()->create([
        'project_id' => $project->id,
        'cluster_id' => $cluster->id,
        'domain' => 'vacated-empty.example.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);

    $empty->update(['target_set_step' => 'reserved']);

    expect(fn () => $empty->update(['status' => RouteStatus::Active]))
        ->not->toThrow(QueryException::class)
        ->and($empty->refresh()->status)
        ->toBe(RouteStatus::Active);
});

it('enforces multi-target storage with compatible Cluster-scoped production rows', function (): void {
    $project = Project::query()->create([
        'name' => 'Acme',
        'slug' => 'acme',
        'repository_url' => 'https://example.test/acme.git',
    ]);
    $cluster = Cluster::query()->create(['name' => 'cluster', 'state' => 'active']);
    $oneNode = route_migration_node('one');
    $twoNode = route_migration_node('two');
    $oneNode->update(['cluster_id' => $cluster->id]);
    $twoNode->update(['cluster_id' => $cluster->id]);
    $oneNode->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $twoNode->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $one = route_migration_instance($project, $oneNode, 'one');
    $two = route_migration_instance($project, $twoNode, 'two');
    $route = Route::query()->create([
        'project_id' => $project->id,
        'cluster_id' => $cluster->id,
        'domain' => 'explicit.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
    ]);
    $route->targets()->create(['instance_id' => $two->id, 'position' => 1]);
    $route->targets()->create(['instance_id' => $one->id, 'position' => 0]);

    expect($route->targets()->pluck('position')->all())
        ->toBe([0, 1])
        ->and(fn () => $route->update(['status' => RouteStatus::Active]))
        ->not
        ->toThrow(QueryException::class)
        ->and(Schema::hasTable('active_app_prod_nodes'))
        ->toBeTrue();
    $one->update(['status' => InstanceState::SourceResolved]);
    $two->update(['status' => InstanceState::SourceResolved]);
    $route->targets()->delete();

    $generated = Route::query()->create([
        'project_id' => $project->id,
        'cluster_id' => $cluster->id,
        'generation_basis_node_id' => $oneNode->id,
        'domain' => 'generated.test',
        'provenance' => RouteProvenance::Generated,
        'publication' => RoutePublication::Private,
    ]);
    $generated->targets()->create(['instance_id' => $one->id, 'position' => 0]);
    expect(fn () => $generated->targets()->create(['instance_id' => $two->id, 'position' => 1]))
        ->toThrow(QueryException::class);
});

function route_migration_node(string $name): Node
{
    static $suffix = 20;

    $suffix++;

    return Node::query()->create([
        'name' => $name,
        'status' => 'active',
        'public_ssh_host' => "{$name}.test",
        'wireguard_ip' => "10.44.0.{$suffix}",
    ]);
}

function route_migration_instance(
    Project $project,
    Node $node,
    string $name,
    RoleName $role = RoleName::AppProd,
): Instance {
    if (! $node->roles()->where('role', $role->value)->exists()) {
        $node->roles()->create(['role' => $role, 'status' => LifecycleStatus::Active]);
    }

    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $name,
        'checkout_path' => "/srv/{$name}",
        'status' => InstanceState::Active,
    ]);

    return $instance;
}

function route_migration_replacement_route(string $suffix, bool $activate = true): Route
{
    [$route] = route_migration_replacement_pair($suffix, $activate);

    return $route;
}

/** @return array{Route, Instance} */
function route_migration_replacement_pair(string $suffix, bool $activate = true): array
{
    $project = Project::query()->create([
        'name' => "Domain {$suffix}",
        'slug' => "domain-{$suffix}",
        'repository_url' => "https://example.test/domain-{$suffix}.git",
    ]);
    $node = route_migration_node("domain-{$suffix}");
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => $suffix,
        'checkout_path' => "/srv/{$suffix}",
        'source_is_laravel' => false,
        'status' => InstanceState::Active,
    ]);
    $route = Route::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'domain' => "{$suffix}.example.test",
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
        'status' => RouteStatus::Pending,
    ]);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);

    if ($activate) {
        $route->update(['status' => RouteStatus::Active]);
    }

    return [$route->refresh(), $instance->refresh()];
}

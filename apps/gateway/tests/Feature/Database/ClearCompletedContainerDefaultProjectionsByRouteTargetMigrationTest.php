<?php

declare(strict_types=1);

use App\Models\Instance;
use Illuminate\Support\Facades\DB;

/** The pre-repair SQL state, isolated from the full migration history and model write hooks. */
function routeTargetProjectionRepairFixture(): void
{
    DB::unprepared(<<<'SQL'
        CREATE TABLE instances (id INTEGER PRIMARY KEY, project_id INTEGER, node_id INTEGER, name TEXT,
            status TEXT, development_projection_pending INTEGER);
        CREATE TABLE instance_runtimes (instance_id INTEGER, seed_commit TEXT);
        CREATE TABLE nodes (id INTEGER PRIMARY KEY, status TEXT);
        CREATE TABLE node_roles (node_id INTEGER, role TEXT, status TEXT);
        CREATE TABLE projects (id INTEGER PRIMARY KEY, type TEXT);
        CREATE TABLE routes (id INTEGER PRIMARY KEY, project_id INTEGER, node_id INTEGER, cluster_id INTEGER,
            generation_basis_node_id INTEGER, web_root TEXT, status TEXT, sites_published INTEGER,
            failed_step TEXT, error_code TEXT, replacement_step TEXT, replaces_route_id INTEGER,
            replaced_by_route_id INTEGER, transition_node_id INTEGER, transition_cluster_id INTEGER,
            target_set_step TEXT, target_set_intent TEXT);
        CREATE TABLE route_targets (route_id INTEGER, instance_id INTEGER, position INTEGER);
        CREATE TABLE operations (target_type TEXT, target_id INTEGER, kind TEXT, status TEXT);
        CREATE TABLE route_publication_holds (node_id INTEGER);
        INSERT INTO instances VALUES (1, 10, 100, 'default', 'active', 1);
        INSERT INTO instance_runtimes VALUES (1, 'dddddddddddddddddddddddddddddddddddddddd');
        INSERT INTO nodes VALUES (100, 'active');
        INSERT INTO node_roles VALUES (100, 'app-dev', 'active');
        INSERT INTO projects VALUES (10, 'laravel-app');
        INSERT INTO routes (id, project_id, node_id, cluster_id, generation_basis_node_id, status, sites_published)
            VALUES (300, 10, NULL, 200, 100, 'active', 1);
        INSERT INTO route_targets VALUES (300, 1, 0);
        SQL);
}

it('repairs a seeded default through its target-linked Route regardless of Route placement', function (?int $node, ?int $basis): void {
    routeTargetProjectionRepairFixture();
    DB::table('routes')->update(['node_id' => $node, 'generation_basis_node_id' => $basis]);
    $before = DB::table('instances')->find(1);
    $route = DB::table('routes')->find(300);
    $runtime = DB::table('instance_runtimes')->first();
    expect(Instance::query()->findOrFail(1)->authoritativeRoute()?->id)->toBe(300);
    $migration = require database_path('migrations/2027_02_05_000002_clear_completed_container_default_projections_by_route_target.php');

    $migration->up();
    $migration->up();
    $migration->down();

    expect((array) DB::table('instances')->find(1))->toBe([...(array) $before, 'development_projection_pending' => 0]);
    expect(DB::table('routes')->find(300))->toEqual($route);
    expect(DB::table('instance_runtimes')->first())->toEqual($runtime);
})->with([
    'cluster-generated Route' => [null, 100],
    'direct Node Route' => [100, null],
    'target ownership without generation basis' => [null, null],
    'target ownership with different placement' => [999, 999],
]);

it('keeps a cluster default pending when completion or ownership is unproven', function (Closure $arrange): void {
    routeTargetProjectionRepairFixture();
    $arrange();

    (require database_path('migrations/2027_02_05_000002_clear_completed_container_default_projections_by_route_target.php'))->up();

    $this->assertDatabaseHas('instances', ['id' => 1, 'development_projection_pending' => 1]);
})->with([
    'host default without container' => fn () => DB::table('instance_runtimes')->delete(),
    'container without seed' => fn () => DB::table('instance_runtimes')->update(['seed_commit' => null]),
    'non-default' => fn () => DB::table('instances')->update(['name' => 'feature']),
    'inactive Instance' => fn () => DB::table('instances')->update(['status' => 'source_resolved']),
    'inactive Node' => fn () => DB::table('nodes')->update(['status' => 'removing']),
    'inactive app-dev' => fn () => DB::table('node_roles')->update(['status' => 'removing']),
    'active app-prod' => fn () => DB::table('node_roles')->insert(['node_id' => 100, 'role' => 'app-prod', 'status' => 'active']),
    'removing app-prod' => fn () => DB::table('node_roles')->insert(['node_id' => 100, 'role' => 'app-prod', 'status' => 'removing']),
    'wrong Project' => fn () => DB::table('routes')->update(['project_id' => 11]),
    'another Instance target' => fn () => DB::table('route_targets')->update(['instance_id' => 2]),
    'web root only' => fn () => DB::table('routes')->update(['web_root' => 'public']),
    'activating publication' => fn () => DB::table('routes')->update(['status' => 'activating']),
    'unpublished sites' => fn () => DB::table('routes')->update(['sites_published' => 0]),
    'failed step' => fn () => DB::table('routes')->update(['failed_step' => 'publish']),
    'error code' => fn () => DB::table('routes')->update(['error_code' => 'caddy.failed']),
    'replacement cleanup' => fn () => DB::table('routes')->update(['replacement_step' => 'pending']),
    'replaces Route' => fn () => DB::table('routes')->update(['replaces_route_id' => 299]),
    'replaced by Route' => fn () => DB::table('routes')->update(['replaced_by_route_id' => 301]),
    'Node transition' => fn () => DB::table('routes')->update(['transition_node_id' => 101]),
    'cluster transition' => fn () => DB::table('routes')->update(['transition_cluster_id' => 201]),
    'target-set step' => fn () => DB::table('routes')->update(['target_set_step' => 'pending']),
    'target-set intent' => fn () => DB::table('routes')->update(['target_set_intent' => '{}']),
    'queued Route publication' => fn () => DB::table('operations')->insert([
        'target_type' => 'route', 'target_id' => 300, 'kind' => 'route.update', 'status' => 'queued']),
    'running Instance publication' => fn () => DB::table('operations')->insert([
        'target_type' => 'instance', 'target_id' => 1, 'kind' => 'instance.migrate', 'status' => 'running']),
    'retained publication hold' => fn () => DB::table('route_publication_holds')->insert(['node_id' => 100]),
]);

it('uses the same Route ordering as deploy admission rather than any matching Route', function (int $otherId, ?string $root, int $selected, int $pending): void {
    routeTargetProjectionRepairFixture();
    $other = [...(array) DB::table('routes')->find(300), 'id' => $otherId, 'project_id' => 11, 'web_root' => $root];
    DB::table('routes')->insert($other);
    DB::table('route_targets')->insert(['route_id' => $otherId, 'instance_id' => 1, 'position' => 0]);
    expect(Instance::query()->findOrFail(1)->authoritativeRoute()?->id)->toBe($selected);

    (require database_path('migrations/2027_02_05_000002_clear_completed_container_default_projections_by_route_target.php'))->up();

    $this->assertDatabaseHas('instances', ['id' => 1, 'development_projection_pending' => $pending]);
})->with([
    'lowest id without root wins even with wrong Project' => [299, null, 299, 1],
    'later wrong Project does not displace own Route' => [301, null, 300, 0],
    'no root preferred to earlier rooted Route' => [299, 'public', 300, 0],
]);

it('keeps a projection pending when another linked Route still publishes', function (): void {
    routeTargetProjectionRepairFixture();
    DB::table('routes')->insert([...(array) DB::table('routes')->find(300), 'id' => 301, 'status' => 'pending']);
    DB::table('route_targets')->insert(['route_id' => 301, 'instance_id' => 1, 'position' => 0]);

    (require database_path('migrations/2027_02_05_000002_clear_completed_container_default_projections_by_route_target.php'))->up();

    $this->assertDatabaseHas('instances', ['id' => 1, 'development_projection_pending' => 1]);
});

it('repairs a seeded non-web default without a Route but keeps a web default pending', function (string $type, int $pending): void {
    routeTargetProjectionRepairFixture();
    DB::table('route_targets')->delete();
    DB::table('routes')->delete();
    DB::table('projects')->update(['type' => $type]);

    (require database_path('migrations/2027_02_05_000002_clear_completed_container_default_projections_by_route_target.php'))->up();

    $this->assertDatabaseHas('instances', ['id' => 1, 'development_projection_pending' => $pending]);
})->with([
    'web Project needs Route' => ['laravel-app', 1],
    'Node package' => ['node-package', 0],
    'Laravel package' => ['laravel-package', 0],
    'monorepo' => ['monorepo', 0],
]);

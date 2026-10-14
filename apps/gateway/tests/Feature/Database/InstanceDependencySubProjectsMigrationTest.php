<?php

declare(strict_types=1);

use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('preserves root observations and attempt history through sub-project migration up and down', function (): void {
    $migration = require database_path('migrations/2026_10_14_160936_add_sub_projects_to_instance_dependencies.php');
    $rows = static fn (string $table): array => DB::table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();

    try {
        $migration->down();
        $node = Node::query()->create(['name' => 'dependency-migration', 'public_ssh_host' => '192.0.2.185', 'status' => 'active']);
        $project = Project::query()->create(['name' => 'Dependency migration', 'slug' => 'dependency-migration', 'repository_url' => 'https://example.test/migration.git']);
        $instance = Instance::query()->create([
            'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'migration', 'environment' => 'development',
            'checkout_path' => '/home/orbit/project', 'source_layout' => 'checkout', 'status' => 'active',
        ]);
        $observation = [
            'instance_id' => $instance->id, 'ecosystem' => 'composer', 'present' => true,
            'observed_at' => '2026-10-14 16:00:00', 'project_root' => '/home/orbit/project',
            'source_reference' => null, 'file_hashes' => '{}', 'format' => 'composer-2',
            'created_at' => '2026-10-14 16:00:00', 'updated_at' => '2026-10-14 16:00:00',
        ];
        $attempt = [
            'instance_id' => $instance->id, 'ecosystem' => 'composer',
            'attempted_at' => '2026-10-14 16:00:00', 'error_code' => null,
            'created_at' => '2026-10-14 16:00:00', 'updated_at' => '2026-10-14 16:00:00',
        ];
        DB::table('instance_dependency_observations')->insert([$observation, [...$observation, 'ecosystem' => 'npm', 'format' => 'npm-3']]);
        DB::table('instance_dependency_scan_attempts')->insert([
            $attempt,
            [...$attempt, 'attempted_at' => '2026-10-14 17:00:00', 'error_code' => 'dependencies.unsupported_format'],
            [...$attempt, 'ecosystem' => 'npm'],
        ]);
        $rootObservations = $rows('instance_dependency_observations');
        $rootAttempts = $rows('instance_dependency_scan_attempts');

        $migration->up();

        expect($rows('instance_dependency_observations'))->toBe(array_map(static fn (array $row): array => [...$row, 'sub_project' => ''], $rootObservations));
        expect($rows('instance_dependency_scan_attempts'))->toBe(array_map(static fn (array $row): array => [...$row, 'sub_project' => ''], $rootAttempts));
        DB::table('instance_dependency_observations')->insert([...$observation, 'sub_project' => 'apps/gateway', 'project_root' => '/home/orbit/project/apps/gateway']);
        DB::table('instance_dependency_scan_attempts')->insert([...$attempt, 'sub_project' => 'apps/gateway']);
        DB::table('instances')->where('id', $instance->id)->update(['dependency_sub_projects' => '["apps/gateway"]']);

        $migration->down();

        expect(Schema::hasColumn('instances', 'dependency_sub_projects'))->toBeFalse();
        expect(Schema::hasColumn('instance_dependency_observations', 'sub_project'))->toBeFalse();
        expect(Schema::hasColumn('instance_dependency_scan_attempts', 'sub_project'))->toBeFalse();
        expect($rows('instance_dependency_observations'))->toBe($rootObservations);
        expect($rows('instance_dependency_scan_attempts'))->toBe($rootAttempts);
    } finally {
        if (! Schema::hasColumn('instance_dependency_observations', 'sub_project')) {
            $migration->up();
        }
    }
});

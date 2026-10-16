<?php

declare(strict_types=1);

use App\Models\Project;
use Illuminate\Support\Facades\DB;

it('migrates stored legacy default steps once and preserves customized commands and metadata', function (): void {
    $project = Project::query()->create(['name' => 'Legacy vp', 'slug' => 'legacy-vp', 'repository_url' => 'https://example.test/legacy.git', 'default_branch' => 'main', 'root' => 'public']);
    $steps = [
        ['npm-build', '/opt/node-v24/bin/node /opt/node-v24/lib/node_modules/npm/bin/npm-cli.js run build', 'vp run build'],
        ['npm-install', 'node /old/npm-cli.js ci', 'vp install --frozen-lockfile'],
        ['custom-build', '/old/node /old/npm-cli.js run build', '/old/node /old/npm-cli.js run build'],
        ['npm-build-custom', 'npm run build -- --base=/custom/', 'npm run build -- --base=/custom/'],
    ];
    foreach ($steps as $position => [$name, $command]) {
        DB::table('project_development_deploy_steps')->insert(['project_id' => $project->id, 'name' => $name, 'command' => $command, 'timeout_seconds' => 321, 'required' => true, 'position' => $position]);
    }
    $migration = require database_path('migrations/2026_11_11_000000_migrate_legacy_vp_deploy_steps.php');
    $migration->up();
    $migration->up();
    foreach ($steps as $position => [$name, , $expected]) {
        $row = DB::table('project_development_deploy_steps')->where('project_id', $project->id)->where('name', $name)->first();
        expect($row->command)->toBe($expected)->and($row->timeout_seconds)->toBe(321)->and($row->position)->toBe($position);
    }
});

it('preserves a customized npm-build instead of treating its hard-coded path as a default', function (): void {
    $project = Project::query()->create(['name' => 'Custom vp', 'slug' => 'custom-vp', 'repository_url' => 'https://example.test/custom.git', 'default_branch' => 'main', 'root' => 'public']);
    $command = '/old/node /old/npm-cli.js run build -- --base=/custom/ && npm run build:ssr';
    DB::table('project_development_deploy_steps')->insert(['project_id' => $project->id, 'name' => 'npm-build', 'command' => $command, 'timeout_seconds' => 321, 'required' => true, 'position' => 0]);
    (require database_path('migrations/2026_11_11_000000_migrate_legacy_vp_deploy_steps.php'))->up();
    expect(DB::table('project_development_deploy_steps')->where('project_id', $project->id)->value('command'))->toBe($command);
});

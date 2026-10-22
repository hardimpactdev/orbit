<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\ProjectLifecycleStep;

it('removes the retired TIA baseline operation and keeps every other step in order', function (): void {
    $orbit = Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/acme/orbit.git']);
    $other = Project::query()->create(['name' => 'Other', 'slug' => 'other', 'repository_url' => 'https://github.com/acme/other.git']);
    $steps = [
        [$orbit, 'setup', 'install', 'composer install'],
        [$orbit, 'setup', 'tia-baseline', '@orbit/tia-baseline'],
        [$orbit, 'setup', 'ci-caches', '@orbit/ci-caches'],
        [$orbit, 'setup', 'migrate', 'php artisan migrate'],
        [$orbit, 'teardown', 'drop', 'php artisan db:wipe'],
        [$other, 'setup', 'baseline', 'echo @orbit/tia-baseline'],
        [$other, 'setup', 'install', 'composer install'],
    ];
    $positions = [];
    foreach ($steps as [$project, $phase, $name, $command]) {
        $position = $positions[$project->id][$phase] = ($positions[$project->id][$phase] ?? -1) + 1;
        ProjectLifecycleStep::query()->create(['project_id' => $project->id, 'phase' => $phase, 'name' => $name, 'command' => $command, 'timeout_seconds' => 60, 'position' => $position]);
    }

    (require database_path('migrations/2026_12_08_000000_remove_tia_baseline_setup_steps.php'))->up();

    $stored = ProjectLifecycleStep::query()->orderBy('project_id')->orderBy('phase')->orderBy('position')->get()
        ->map(static fn (ProjectLifecycleStep $step): array => [$step->project_id, $step->phase, $step->position, $step->name, $step->command])->all();
    expect($stored)->toBe([
        [$orbit->id, 'setup', 0, 'install', 'composer install'],
        [$orbit->id, 'setup', 1, 'ci-caches', '@orbit/ci-caches'],
        [$orbit->id, 'setup', 2, 'migrate', 'php artisan migrate'],
        [$orbit->id, 'teardown', 0, 'drop', 'php artisan db:wipe'],
        [$other->id, 'setup', 0, 'baseline', 'echo @orbit/tia-baseline'],
        [$other->id, 'setup', 1, 'install', 'composer install'],
    ]);
});

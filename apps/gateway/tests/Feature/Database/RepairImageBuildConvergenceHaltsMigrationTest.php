<?php

declare(strict_types=1);

use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Models\Node;
use App\Models\TaskVmImageBuild;

function imageBuildHaltMigrationFixture(): TaskVmImageBuild
{
    $host = Node::query()->create(['name' => 'image-host', 'status' => 'active', 'public_ssh_host' => 'image-host.test']);

    return TaskVmImageBuild::query()->create(['host_node_id' => $host->id, 'recipe_identity' => 'sha256:'.str_repeat('d', 64),
        'recipe_generation' => 14, 'state' => TaskVmImageBuildState::Succeeded]);
}

function storedImageBuildHaltMessage(TaskVmImageBuild $build): string
{
    return "Machine recipe [{$build->recipe_identity}] halted: Image build [{$build->id}] failed on host [{$build->host_node_id}]. Inspect its build steps before recovery. No automatic rollback or retry.";
}

it('repairs a stored build-failure halt once and preserves prior Node progress', function (): void {
    $build = imageBuildHaltMigrationFixture();
    $nodes = ['42' => ['name' => 'visited', 'changes' => []]];
    $build->forceFill(['fleet_convergence' => ['generation' => 14, 'status' => 'halted', 'nodes' => $nodes, 'node_id' => 42,
        'message' => storedImageBuildHaltMessage($build), 'finished_at' => now()->toIso8601String(), 'alert' => ['outcome' => 'sent']]])->save();
    $migration = require database_path('migrations/2026_12_02_000000_repair_image_build_convergence_halts.php');

    $migration->up();
    $migration->up();

    expect($build->refresh()->fleet_convergence)->toBe(['generation' => 14, 'status' => 'ready', 'nodes' => $nodes, 'node_id' => 42]);
});

it('preserves Node-failure and interrupted-visit halts', function (string $message): void {
    $build = imageBuildHaltMigrationFixture();
    $message = str_replace('{image_failure}', storedImageBuildHaltMessage($build), $message);
    $progress = ['generation' => 14, 'status' => 'halted', 'node_id' => $build->host_node_id, 'nodes' => [],
        'message' => "Machine recipe [{$build->recipe_identity}] halted: {$message} No automatic rollback or retry.",
        'finished_at' => now()->toIso8601String(), 'alert' => ['outcome' => 'sent']];
    $build->forceFill(['fleet_convergence' => $progress])->save();
    $migration = require database_path('migrations/2026_12_02_000000_repair_image_build_convergence_halts.php');

    $migration->up();

    expect($build->refresh()->fleet_convergence)->toBe($progress);
})->with([
    'Node failure' => 'Node [9: Beast] failed: Doctor verification failed.',
    'interrupted visit' => 'Interrupted visit on Node 9. Inspect Doctor before manual recovery.',
    'Node error embeds image failure' => 'Node [9: Beast] failed: {image_failure}',
]);

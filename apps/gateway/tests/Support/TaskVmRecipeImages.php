<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Models\TaskVmImageBuild;

final class TaskVmRecipeImages
{
    public static function ready(int $hostId, string $fingerprint = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'): TaskVmImageBuild
    {
        $recipe = app(RecipeLoader::class)->load();
        $images = [];
        foreach (['base'] as $profile) {
            $images[$profile] = ['project' => 'orbit-tasks', 'alias' => RecipeImageBuilds::alias(TaskVmHost::BaseImage, $recipe->identity, $profile),
                'fingerprint' => $profile === 'base' ? $fingerprint : hash('sha256', $profile), 'type' => 'virtual-machine'];
        }

        return TaskVmImageBuild::query()->updateOrCreate(['host_node_id' => $hostId, 'recipe_identity' => $recipe->identity],
            ['recipe_json' => $recipe->json, 'recipe_generation' => 1, 'images' => $images, 'state' => TaskVmImageBuildState::Succeeded,
                'image_fingerprint' => $fingerprint, 'promoted_at' => now()]);
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmSettings;
use App\Models\TaskVmImageBuild;

/** Prunes only host-local recipe aliases whose build records establish ownership. */
final readonly class RecipeImagePruning
{
    public function __construct(private TaskVmSettings $settings, private IncusHost $incus, private IncusTaskVmBaseImage $image) {}

    public function prune(TaskVmImageBuild $current): void
    {
        $host = $this->settings->host($current->host_node_id);
        $node = $current->hostNode;
        $builds = TaskVmImageBuild::query()->where('host_node_id', $node->id)->whereNotNull('recipe_identity')
            ->orderByDesc('promoted_at')->orderByDesc('id')->get();
        $previous = $builds->first(static fn (TaskVmImageBuild $build): bool => $build->recipe_identity !== $current->recipe_identity && $build->promoted_at !== null);
        $obsolete = [];
        $protected = array_values($this->image->instances($host, $node));
        foreach ($builds as $build) {
            $keep = $build->id === $current->id || $build->id === $previous?->id
                || $build->state === TaskVmImageBuildState::Running;
            foreach (['base', ...RecipeImageBuilds::Roles] as $profile) {
                $alias = RecipeImageBuilds::alias($host->imageAlias, (string) $build->recipe_identity, $profile);
                if (! $keep) {
                    $obsolete[] = $alias;
                }
            }
            if ($keep) {
                $protected = [...$protected, ...array_column($build->images, 'fingerprint')];
            }
        }
        foreach ($this->incus->images($host, $node) as $fingerprint => $aliases) {
            // Unrelated aliases, including the current base alias, also protect a shared fingerprint.
            if ($aliases === [] || array_diff($aliases, $obsolete) !== [] || in_array($fingerprint, $protected, true)) {
                continue;
            }
            $result = $this->incus->run($host, $node, ['image', 'delete', '--', $fingerprint]);
            if (! $result->succeeded()) {
                throw IncusHost::failed("the recipe image [{$fingerprint}]", 'image delete', $result);
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmSettings;
use App\Models\TaskVmImageBuild;

/**
 * Prunes only host-local recipe aliases whose build records establish ownership. A base image goes with its topology
 * seed, so a kept base keeps its seed. Incus keeps the dataset of a seed whose clones VMs still use until the last
 * clone goes.
 */
final readonly class RecipeImagePruning
{
    /** Retired images still exist on hosts; retain these names only for cleanup. */
    private const array RetiredProfiles = ['gateway', 'app-dev', 'app-prod', 'operator'];

    public function __construct(private TaskVmSettings $settings, private IncusHost $incus) {}

    public function prune(TaskVmImageBuild $current): void
    {
        $host = $this->settings->host($current->host_node_id);
        $node = $current->hostNode;
        $builds = TaskVmImageBuild::query()->where('host_node_id', $node->id)->whereNotNull('recipe_identity')
            ->orderByDesc('promoted_at')->orderByDesc('id')->get();
        $previous = $builds->first(static fn (TaskVmImageBuild $build): bool => $build->id !== $current->id && $build->state === TaskVmImageBuildState::Succeeded && $build->promoted_at !== null);
        $obsolete = [];
        $protected = [];
        foreach ($builds as $build) {
            $running = $build->id !== $current->id && $build->state === TaskVmImageBuildState::Running;
            $keepBase = $build->id === $current->id || $build->id === $previous?->id || $running;
            if (! $keepBase) {
                // Publishing may finish before its reply or the image metadata is persisted.
                $obsolete[] = RecipeImageBuilds::alias($host->imageAlias, (string) $build->recipe_identity, 'base', $build->id);
                if (isset($build->images['base'])) {
                    $obsolete[] = $build->images['base']['alias'];
                }
            }
            if (! $running) {
                foreach (self::RetiredProfiles as $profile) {
                    if (isset($build->images[$profile])) {
                        $obsolete[] = $build->images[$profile]['alias'];
                    }
                }
            }
            if ($running) {
                $protected = [...$protected, ...array_column($build->images, 'fingerprint')];
            } elseif ($keepBase && isset($build->images['base'])) {
                $protected[] = $build->images['base']['fingerprint'];
            }
        }
        $obsolete = array_values(array_unique($obsolete));
        $retired = array_values(array_filter($obsolete, static fn (string $alias): bool => array_any(self::RetiredProfiles, static fn (string $profile): bool => str_ends_with($alias, '-'.$profile))));
        $this->incus->image($host, $node, [
            'kind' => 'prune', 'pool' => $host->pool, 'obsolete_aliases' => $obsolete,
            'retired_aliases' => $retired, 'protected_fingerprints' => array_values(array_unique($protected)),
        ]);
    }
}

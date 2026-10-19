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

    public function __construct(private TaskVmSettings $settings, private IncusHost $incus, private IncusTaskVmBaseImage $image, private TaskVmVolumes $volumes) {}

    public function prune(TaskVmImageBuild $current): void
    {
        $host = $this->settings->host($current->host_node_id);
        $node = $current->hostNode;
        $builds = TaskVmImageBuild::query()->where('host_node_id', $node->id)->whereNotNull('recipe_identity')
            ->orderByDesc('promoted_at')->orderByDesc('id')->get();
        $previous = $builds->first(static fn (TaskVmImageBuild $build): bool => $build->id !== $current->id && $build->state === TaskVmImageBuildState::Succeeded && $build->promoted_at !== null);
        $obsolete = [];
        $inUse = array_values($this->image->instances($host, $node));
        $protected = $inUse;
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
        foreach ($this->incus->images($host, $node) as $fingerprint => $aliases) {
            // Unrelated aliases, including the current base alias, also protect a shared fingerprint.
            if ($aliases === [] || array_diff($aliases, $obsolete) !== [] || in_array($fingerprint, $protected, true)) {
                if (in_array($fingerprint, $inUse, true)) {
                    continue;
                }
                foreach (array_intersect($aliases, $obsolete) as $alias) {
                    // Keep protected images, but retire their obsolete role aliases.
                    if (! array_any(self::RetiredProfiles, static fn (string $profile): bool => str_ends_with($alias, '-'.$profile))) {
                        continue;
                    }
                    $result = $this->incus->run($host, $node, ['image', 'alias', 'delete', '--', $alias]);
                    if (! $result->succeeded()) {
                        throw IncusHost::failed("the retired recipe alias [{$alias}]", 'image alias delete', $result);
                    }
                }

                continue;
            }
            // The seed goes first: a failed deletion keeps the image, so the next prune finds the seed again.
            $this->volumes->delete($host, $node, TaskVmVolumes::seed($fingerprint));
            $this->volumes->delete($host, $node, TaskVmVolumes::newSeed($fingerprint));
            $result = $this->incus->run($host, $node, ['image', 'delete', '--', $fingerprint]);
            if (! $result->succeeded()) {
                throw IncusHost::failed("the recipe image [{$fingerprint}]", 'image delete', $result);
            }
        }
    }
}

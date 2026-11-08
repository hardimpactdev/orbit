<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\TaskVmImageBuild;

/**
 * The base a host's task VMs start from: the snapshot of its base VM that its latest refresh recorded
 * (docs/cli/tasks/vm-image-refresh.mmd), which a task start copies. Only a refresh makes a base. The output of the retired recipe image
 * build is an image, not a snapshot, so it is never a base and never replaces the snapshot.
 */
final readonly class TaskVmPromotedImage
{
    /** The base VM of a host and its seed volume. */
    public static function baseVm(Node $host): string
    {
        return $host->name.'-base';
    }

    public static function baseSeed(Node $host): string
    {
        return $host->name.'-base-seed';
    }

    /**
     * The host's recorded base snapshot and the recipe identity it converged to, or null before the host's first refresh.
     *
     * @return array{recipe_identity:string,snapshot:string}|null
     */
    public function recorded(Node $host, TaskVmHost $location): ?array
    {
        $build = $this->latest($host);
        $base = $build?->images['base'] ?? null;
        if (! $build instanceof TaskVmImageBuild || ! is_array($base) || ($base['project'] ?? null) !== $location->project
            || ($base['type'] ?? null) !== 'snapshot' || ! is_string($base['snapshot'] ?? null) || $base['snapshot'] === '') {
            return null;
        }

        return ['recipe_identity' => (string) $build->recipe_identity, 'snapshot' => $base['snapshot']];
    }

    /**
     * The base a task start needs: the recorded snapshot, which only a refresh makes.
     *
     * @return array{recipe_identity:string,snapshot:string}
     */
    public function forHost(Node $host, TaskVmHost $location): array
    {
        return $this->recorded($host, $location)
            ?? throw new ResourceOperationException('task_vm.image_unavailable', 'The host has no base snapshot; only a refresh makes one.', 409);
    }

    /**
     * The base snapshot a task start copies.
     *
     * @return array{vm:string,seed:string,snapshot:string,recipe_identity:string}
     */
    public function snapshot(Node $host, TaskVmHost $location): array
    {
        $base = $this->forHost($host, $location);

        return ['vm' => self::baseVm($host), 'seed' => self::baseSeed($host), 'snapshot' => $base['snapshot'], 'recipe_identity' => $base['recipe_identity']];
    }

    /** Withdraws the host's recorded base: no start copies it, and the next refresh builds the base as on a new host. */
    public function withdraw(Node $host): void
    {
        TaskVmImageBuild::query()->where('host_node_id', $host->id)->whereNotNull('promoted_at')->update(['promoted_at' => null]);
    }

    /** The host's latest promoted refresh. A promoted image of the retired build is skipped, so it never replaces the base snapshot. */
    public function latest(Node $host): ?TaskVmImageBuild
    {
        return TaskVmImageBuild::query()->where('host_node_id', $host->id)->where('state', TaskVmImageBuildState::Succeeded)
            ->where('images->base->type', 'snapshot')->whereNotNull('promoted_at')->orderByDesc('promoted_at')->latest('id')->first();
    }
}

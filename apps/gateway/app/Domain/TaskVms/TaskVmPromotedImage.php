<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\TaskVmImageBuild;
use Illuminate\Support\Str;

/**
 * The base a host's task VMs start from: its latest promoted build. A refresh records a snapshot of the host's base VM
 * (docs/cli/tasks/vm-image-refresh.mmd), which a task start copies. A recipe image build records an image, from which the first refresh
 * makes the base VM once.
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
     * The host's recorded base: a snapshot of its base VM, or before its first refresh, the recipe image.
     *
     * @return array{recipe_identity:string,snapshot:string|null,image:string|null}
     */
    public function forHost(Node $host, TaskVmHost $location): array
    {
        $build = $this->latest($host);
        $base = $build?->images['base'] ?? null;
        if (! $build instanceof TaskVmImageBuild || ! is_array($base) || ($base['project'] ?? null) !== $location->project) {
            throw new ResourceOperationException('task_vm.image_unavailable', 'The host has no promoted task base.', 409);
        }
        $snapshot = ($base['type'] ?? null) === 'snapshot' && is_string($base['snapshot'] ?? null) ? $base['snapshot'] : null;
        $image = ($base['type'] ?? null) === 'virtual-machine' && Str::of((string) ($base['fingerprint'] ?? ''))->isMatch('/\A[a-f0-9]{12,64}\z/D') ? $base['fingerprint'] : null;
        if ($snapshot === null && $image === null) {
            throw new ResourceOperationException('task_vm.image_unavailable', 'The host has no promoted task base.', 409);
        }

        return ['recipe_identity' => (string) $build->recipe_identity, 'snapshot' => $snapshot, 'image' => $image];
    }

    /**
     * The base snapshot a task start copies.
     *
     * @return array{vm:string,seed:string,snapshot:string,recipe_identity:string}
     */
    public function snapshot(Node $host, TaskVmHost $location): array
    {
        $base = $this->forHost($host, $location);
        if ($base['snapshot'] === null) {
            throw new ResourceOperationException('task_vm.image_unavailable', 'The host has no base snapshot yet; its first refresh makes one.', 409);
        }

        return ['vm' => self::baseVm($host), 'seed' => self::baseSeed($host), 'snapshot' => $base['snapshot'], 'recipe_identity' => $base['recipe_identity']];
    }

    /** The host's latest promoted build. */
    public function latest(Node $host): ?TaskVmImageBuild
    {
        return TaskVmImageBuild::query()->where('host_node_id', $host->id)->where('state', TaskVmImageBuildState::Succeeded)
            ->whereNotNull('promoted_at')->orderByDesc('promoted_at')->latest('id')->first();
    }
}

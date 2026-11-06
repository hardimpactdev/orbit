<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\TaskVmImageBuild;
use Illuminate\Support\Str;

/**
 * The task image that a host's VMs boot from: its latest promoted build, whatever the current recipe is. A proof VM or a
 * seed refresh needs a bootable image, not the current recipe. The image's topology seed is named from its
 * fingerprint, so this is also how a caller finds the seed that belongs to the image.
 */
final readonly class TaskVmPromotedImage
{
    /** @return array{fingerprint:string,recipe_identity:string} */
    public function forHost(Node $host, TaskVmHost $location): array
    {
        $build = $this->latest($host);
        $base = $build?->images['base'] ?? null;
        if (! $build instanceof TaskVmImageBuild || $base === null || $base['project'] !== $location->project || $base['type'] !== 'virtual-machine'
            || ! Str::of($base['fingerprint'])->isMatch('/\A[a-f0-9]{12,64}\z/D')) {
            throw new ResourceOperationException('task_vm.image_unavailable', 'The host has no promoted task image for a proof VM.', 409);
        }

        return ['fingerprint' => $base['fingerprint'], 'recipe_identity' => (string) $build->recipe_identity];
    }

    /** The host's latest promoted image build, whatever its recipe. */
    public function latest(Node $host): ?TaskVmImageBuild
    {
        return TaskVmImageBuild::query()->where('host_node_id', $host->id)->where('state', TaskVmImageBuildState::Succeeded)
            ->whereNotNull('promoted_at')->orderByDesc('promoted_at')->latest('id')->first();
    }
}

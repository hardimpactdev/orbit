<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmSettings;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;

/** Transfers only this VM's pinned host-local base image, on the task-vms worker before it becomes ready. */
final readonly class TaskVmRoleImages
{
    public const string Directory = '/var/lib/orbit/task-vm-role-images';

    public function __construct(private IncusHost $incus, private TaskVmSettings $settings) {}

    public function prepare(TaskVm $vm): void
    {
        if ($vm->recipe_identity === null || $vm->group_id === null || $vm->size->resources()->topologyPool === null) {
            return;
        }
        $host = $this->settings->host((int) $vm->host_node_id);
        $build = TaskVmImageBuild::query()->where('host_node_id', $host->nodeId)->where('recipe_identity', $vm->recipe_identity)
            ->where('state', TaskVmImageBuildState::Succeeded)->whereNotNull('promoted_at')->first();
        $image = $build?->images['base'] ?? null;
        if ($image === null || $image['project'] !== $host->project || $image['type'] !== 'virtual-machine' || $image['fingerprint'] !== $vm->image_fingerprint) {
            throw new TaskVmException('task_vm.recipe_images_missing', 'The claimed recipe base image is unavailable on the task VM host.');
        }
        $manifest = json_encode(['recipe_identity' => $vm->recipe_identity, 'images' => ['base' => $image]], JSON_THROW_ON_ERROR);
        $result = $this->incus->root($vm->incusHostNode(), ['bash', '-s', '--', $host->project, $vm->name, $manifest], TaskVmScripts::read('role-images-transfer.sh'), 1200);
        if (! $result->succeeded() || $result->truncated) {
            throw new TaskVmException('task_vm.recipe_images_transfer_failed', 'The host could not transfer the claimed recipe base image to the task VM.');
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\Processes\CommandResult;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;

/**
 * Puts only this VM's pinned host-local base image into a VM that can run a topology, with
 * `resources/task-vms/role-images-transfer.sh` on its host. The task-vms worker transfers it before the VM becomes
 * ready, and again when topology admission finds the VM without it ({@see TaskVmTopologyAdmission}).
 */
final readonly class TaskVmRoleImages
{
    public const string Directory = '/var/lib/orbit/task-vm-role-images';

    private const string Script = 'role-images-transfer.sh';

    /** The exit code of `check` when the VM does not hold the manifest. */
    private const int Absent = 3;

    /** The exit codes of the script when it refuses its arguments or the manifest: no retry changes them. */
    private const array Refused = [64, 65];

    /** A check reads one small file, so it never holds a tick for long. */
    private const float CheckSeconds = 15.0;

    public function __construct(private IncusHost $incus, private TaskVmSettings $settings) {}

    /**
     * Whether the VM holds the manifest of its claimed base image. A VM that runs no topology needs none.
     *
     * @throws TaskVmException `task_vm.recipe_images_unreadable` when the host cannot tell now, and a permanent
     *                         error such as `task_vm.recipe_images_missing` or `task_vm.recipe_images_refused`
     */
    public function present(TaskVm $vm): bool
    {
        $result = $this->run($vm, 'check', self::CheckSeconds);

        if (! $result instanceof CommandResult) {
            return true;
        }
        if (in_array($result->exitCode, self::Refused, true)) {
            throw new TaskVmException(TaskVmError::RecipeImagesRefused, 'The host refused the recipe image manifest of the task VM.'.self::detail($result));
        }

        return match ($result->truncated ? null : $result->exitCode) {
            0 => true,
            self::Absent => false,
            default => throw new TaskVmException(TaskVmError::RecipeImagesUnreadable, 'The host could not read the recipe image manifest in the task VM.'.self::detail($result)),
        };
    }

    /**
     * Transfers the claimed base image unless the VM holds it already. The script proves the manifest in the VM last.
     *
     * @throws TaskVmException `task_vm.recipe_images_transfer_failed` when the VM does not hold it afterwards
     */
    public function prepare(TaskVm $vm): void
    {
        $result = $this->run($vm, 'transfer', 1200.0);
        if ($result instanceof CommandResult && (! $result->succeeded() || $result->truncated)) {
            throw new TaskVmException(TaskVmError::RecipeImagesTransferFailed, 'The host could not transfer the claimed recipe base image to the task VM.'.self::detail($result));
        }
    }

    /** Runs the script in `$mode`, or nothing for a VM that runs no topology. */
    private function run(TaskVm $vm, string $mode, float $timeout): ?CommandResult
    {
        if ($vm->recipe_identity === null || $vm->group_id === null || $vm->size->resources()->topologyPool === null) {
            return null;
        }
        $host = $this->settings->host((int) $vm->host_node_id);
        $build = TaskVmImageBuild::query()->where('host_node_id', $host->nodeId)->where('recipe_identity', $vm->recipe_identity)
            ->where('state', TaskVmImageBuildState::Succeeded)->whereNotNull('promoted_at')->first();
        $image = $build?->images['base'] ?? null;
        if ($image === null || $image['project'] !== $host->project || $image['type'] !== 'virtual-machine' || $image['fingerprint'] !== $vm->image_fingerprint) {
            throw new TaskVmException(TaskVmError::RecipeImagesMissing, 'The claimed recipe base image is unavailable on the task VM host.');
        }
        $manifest = json_encode(['recipe_identity' => $vm->recipe_identity, 'images' => ['base' => $image]], JSON_THROW_ON_ERROR);

        return $this->incus->root($vm->incusHostNode(), TaskVmScripts::bash(self::Script, [$mode, $host->project, $vm->name, $manifest]), timeout: $timeout);
    }

    private static function detail(CommandResult $result): string
    {
        $detail = mb_substr(implode("\n", array_slice(explode("\n", trim($result->stderr)), -5)), 0, 2000);

        return " Exit code [{$result->exitCode}].".($detail === '' ? '' : "\n{$detail}");
    }
}

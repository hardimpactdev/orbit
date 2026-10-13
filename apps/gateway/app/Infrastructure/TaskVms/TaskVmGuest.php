<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmGuestCommand;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\VmObservation;
use App\Infrastructure\Processes\CommandResult;
use App\Models\TaskVm;
use Illuminate\Support\Sleep;
use JsonException;

/**
 * Reads a task VM's guest before it is a Node, the same way for every provider: the cloud-init status, the image
 * manifest, and whether the VM runs. Each provider only runs the fixed {@see TaskVmGuestCommand}; this class
 * validates the output, so every provider fails with the same codes. Invalid output fails with
 * `task_vm.invalid_host_output`, and a guest command that fails with `task_vm.host_command_failed`.
 */
final readonly class TaskVmGuest
{
    /** A guest reboot shows the VM stopped for about a second, so a stopped VM is read again after this wait. */
    private const int RebootSeconds = 5;

    public function __construct(private TaskVmProvider $provider) {}

    /**
     * True when cloud-init finished without errors. False while the guest cannot run the command yet, or cloud-init
     * still runs.
     *
     * @throws TaskVmException `task_vm.bootstrap_failed` when cloud-init reports errors
     */
    public function bootstrapReady(TaskVm $vm): bool
    {
        // Waits in the guest until cloud-init is done, so the caller hears it at once instead of on its next poll.
        $result = $this->provider->exec($vm, TaskVmGuestCommand::CloudInitStatus);
        // Until the guest agent or sshd runs, the command fails and prints nothing on stdout.
        if (! $result->succeeded() && trim($result->stdout) === '') {
            return false;
        }

        // `cloud-init status` exits 1 or 2 after errors, but still prints its JSON.
        $status = self::json($vm, $result);
        $errors = $status['errors'] ?? null;
        if (! is_string($status['status'] ?? null) || ! is_array($errors) || ! array_is_list($errors)) {
            throw self::invalid($vm, 'the cloud-init status has no status or errors');
        }
        if ($status['status'] === 'error') {
            throw new TaskVmException('task_vm.bootstrap_failed', "Cloud-init failed on task VM [{$vm->name}]: ".mb_substr(json_encode($errors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 0, 2000), 502);
        }

        return $status['status'] === 'done' && $errors === [];
    }

    /** The manifest of the programs the VM's base image ran, or null when the image has none. */
    public function imageManifest(TaskVm $vm): ?string
    {
        $result = $this->provider->exec($vm, TaskVmGuestCommand::ImageManifest);
        if (! $result->succeeded() || $result->truncated) {
            throw self::failed($vm, 'the image manifest read', $result);
        }

        return $result->stdout === 'absent' ? null : $result->stdout;
    }

    /** What the provider sees of the VM. A stopped VM gets a second reading, because a guest reboot shows it stopped. */
    public function observeSettled(TaskVm $vm): ?VmObservation
    {
        $observation = $this->provider->observe($vm);
        if ($observation?->running === false) {
            Sleep::for(self::RebootSeconds)->seconds();
            $observation = $this->provider->observe($vm);
        }

        return $observation;
    }

    /** @return array<mixed> */
    private static function json(TaskVm $vm, CommandResult $result): array
    {
        try {
            $value = $result->truncated ? null : json_decode($result->stdout, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $value = null;
        }

        return is_array($value) ? $value : throw self::invalid($vm, 'the cloud-init status is not complete JSON');
    }

    private static function invalid(TaskVm $vm, string $reason): TaskVmException
    {
        return new TaskVmException('task_vm.invalid_host_output', "Task VM [{$vm->name}] returned invalid guest output: {$reason}.", 502);
    }

    private static function failed(TaskVm $vm, string $operation, CommandResult $result): TaskVmException
    {
        $detail = mb_substr(implode("\n", array_slice(explode("\n", trim($result->stderr)), -5)), 0, 2000);

        return new TaskVmException('task_vm.host_command_failed', "On task VM [{$vm->name}], {$operation} failed with exit code [{$result->exitCode}]".($result->truncated ? ' and truncated output' : '').'.'.($detail === '' ? '' : "\n{$detail}"), 502);
    }
}

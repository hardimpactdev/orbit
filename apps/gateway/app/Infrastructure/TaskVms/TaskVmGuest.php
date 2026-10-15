<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmGuestCommand;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\VmObservation;
use App\Infrastructure\Processes\CommandResult;
use App\Models\TaskVm;
use Closure;
use Illuminate\Support\Sleep;
use JsonException;

/**
 * Reads a task VM's guest before it is a Node, the same way for every provider: the cloud-init status and
 * whether the VM runs. Each provider only runs the fixed {@see TaskVmGuestCommand}; this class
 * validates the output, so every provider fails with the same codes. Invalid output fails with
 * `task_vm.invalid_host_output`, and a guest command that fails with `task_vm.host_command_failed`. The base image
 * builds read cloud-init in their builder and smoke VMs with the same rules
 * ({@see self::cloudInitDone()}, {@see self::awaitCloudInit()}).
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
        return self::cloudInitDone("task VM [{$vm->name}]", $this->provider->exec($vm, TaskVmGuestCommand::CloudInitStatus));
    }

    /**
     * Reads the output of {@see TaskVmGuestCommand::CloudInitStatus} on `$subject`, such as `task VM [tvm-7]`. True
     * when cloud-init is done without errors. False while the guest cannot run the command yet, which fails and
     * prints nothing on stdout, or while cloud-init still runs.
     *
     * @throws TaskVmException `task_vm.bootstrap_failed` when cloud-init reports an error or is done with errors,
     *                         `task_vm.invalid_host_output` when the output is no complete status
     */
    public static function cloudInitDone(string $subject, CommandResult $result): bool
    {
        // Until the guest agent or sshd runs, the command fails and prints nothing on stdout.
        if (! $result->succeeded() && trim($result->stdout) === '') {
            return false;
        }

        // `cloud-init status` exits 1 or 2 after errors, but still prints its JSON.
        try {
            $status = $result->truncated ? null : json_decode($result->stdout, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $status = null;
        }
        if (! is_array($status)) {
            throw self::invalid($subject, 'the cloud-init status is not complete JSON');
        }
        $errors = $status['errors'] ?? null;
        if (! is_string($status['status'] ?? null) || ! is_array($errors) || ! array_is_list($errors)) {
            throw self::invalid($subject, 'the cloud-init status has no status or errors');
        }
        if ($status['status'] === 'error' || ($status['status'] === 'done' && $errors !== [])) {
            throw new TaskVmException('task_vm.bootstrap_failed', "Cloud-init failed on {$subject}: ".mb_substr(json_encode($errors, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 0, 2000), 502);
        }

        return $status['status'] === 'done';
    }

    /**
     * Runs {@see TaskVmGuestCommand::CloudInitStatus} with `$run` until cloud-init is done on `$subject`, at most
     * `$seconds` after now. The command waits in the guest, so a pause between reads is only needed while the guest
     * cannot run it yet.
     *
     * @param  Closure(list<string>, float): CommandResult  $run  runs the argv with its timeout in the guest
     *
     * @throws TaskVmException `task_vm.bootstrap_timeout` when cloud-init is not done in time, else as {@see self::cloudInitDone()}
     */
    public static function awaitCloudInit(string $subject, int $seconds, Closure $run): void
    {
        $deadline = now()->getTimestamp() + $seconds;
        $command = TaskVmGuestCommand::CloudInitStatus;
        while (! self::cloudInitDone($subject, $run($command->argv(), (float) $command->timeoutSeconds()))) {
            if (now()->getTimestamp() >= $deadline) {
                throw new TaskVmException('task_vm.bootstrap_timeout', "Cloud-init on {$subject} was not done {$seconds} seconds after launch.", 504);
            }
            Sleep::for(3)->seconds();
        }
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

    private static function invalid(string $subject, string $reason): TaskVmException
    {
        return new TaskVmException('task_vm.invalid_host_output', ucfirst($subject)." returned invalid guest output: {$reason}.", 502);
    }
}

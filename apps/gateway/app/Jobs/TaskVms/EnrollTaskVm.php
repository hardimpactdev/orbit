<?php

declare(strict_types=1);

namespace App\Jobs\TaskVms;

use App\Actions\Nodes\ProvisionNodeAction;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\TaskVmGuest;
use App\Infrastructure\TaskVms\TaskVmStepClock;
use App\Models\TaskVm;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Sleep;

/**
 * Waits for cloud-init, reads the guest address, host key and image manifest from the host, and enrolls
 * the VM as the `app-dev` Node `tvm-<id>` through the host as jump Node. The guest values are read only
 * here, before any code but cloud-init has run in the VM. It never creates the VM again.
 */
final class EnrollTaskVm implements ShouldBeUnique, ShouldQueue
{
    use ProvisionsTaskVm;

    /** Each release counts as a try. 60 tries cover the boot window many times over; they also bound a job that a worker stop keeps cutting off. */
    public int $tries = 60;

    public int $maxExceptions = 3;

    public int $backoff = 30;

    public int $timeout = 1500;

    /**
     * A VM from the base image is done with cloud-init about 10 seconds after launch. Once the guest agent
     * runs, one check waits in the guest until cloud-init is done. Before that the job checks every second,
     * for up to 2 minutes in one run, so the VM is ready without a release and a second pass through the queue.
     */
    private const int InlinePollSeconds = 1;

    private const int InlinePolls = 120;

    private const int PollSeconds = 15;

    private const int BootstrapMinutes = 10;

    /** Failures that a retry cannot fix. */
    private const array Permanent = [TaskVmError::BootstrapFailed, TaskVmError::BootstrapTimeout, TaskVmError::VmNotRunning, TaskVmError::ImageMismatch];

    /** @param  int  $launchedAt  when `ProvisionTaskVm` launched the VM: the boot limit counts from then, not from the row, because jobs run one at a time */
    public function __construct(public int $taskVmId, public int $launchedAt)
    {
        $this->onTaskVmQueue();
    }

    public function handle(TaskVmProvider $provider, TaskVmGuest $guest, ProvisionNodeAction $nodes, TaskVmSettings $settings, TaskVmStepClock $clock): void
    {
        $vm = $this->provisioning();
        if (! $vm instanceof TaskVm) {
            return;
        }
        if ($vm->node?->status === LifecycleStatus::Active) {
            PrepareTaskVmRuntime::dispatch($vm->id);

            return;
        }

        // `enroll-queue` is the wait from launch for the worker; the guest boots meanwhile. `boot` is the rest of the
        // boot, or the whole boot from launch when an earlier try released the job.
        $clock->start($vm, $this->launchedAt);
        if ($this->attempts() <= 1) {
            $clock->step('enroll-queue');
        }
        try {
            $address = $this->bootstrappedAddress($vm, $provider, $guest);
            for ($poll = 0; $address === null && $poll < self::InlinePolls; $poll++) {
                Sleep::for(self::InlinePollSeconds)->seconds();
                $address = $this->bootstrappedAddress($vm, $provider, $guest);
            }
        } catch (TaskVmException $exception) {
            if (! in_array(TaskVmError::tryFrom($exception->errorCode), self::Permanent, true)) {
                throw $exception;
            }
            $this->fail($exception);

            return;
        }
        if ($address === null) {
            $this->release(self::PollSeconds);

            return;
        }

        $clock->step('boot');
        $fingerprint = $provider->sshHostFingerprint($vm);
        $vm->update(['address' => $address]);
        $nodes->execute(new ProvisionNodeData(
            name: $vm->name,
            publicSshHost: $address,
            roles: [RoleName::AppDev],
            user: 'orbit',
            orbitUser: 'orbit',
            wireguardIp: $vm->wireguard_ip,
            expectedSshHostFingerprint: $fingerprint,
            clusterProvided: true,
            clusterId: $settings->devClusterId,
            sshJumpNodeId: $vm->host_node_id,
            taskVmId: $vm->id,
        ));
        $clock->step('enrolled');
        $clock->stop();
        PrepareTaskVmRuntime::dispatch($vm->id);
    }

    /**
     * The guest's bridge address once cloud-init is done without errors, or null while the VM boots.
     *
     * @throws TaskVmException
     */
    private function bootstrappedAddress(TaskVm $vm, TaskVmProvider $provider, TaskVmGuest $guest): ?string
    {
        $observation = $guest->observeSettled($vm);
        if ($observation?->running !== true) {
            throw new TaskVmException(TaskVmError::VmNotRunning, "Task VM [{$vm->name}] is ".($observation === null ? 'absent' : 'stopped').' on its host.', 502);
        }
        // Cloud-init is done only after the network is online, so the host knows the address by then. The
        // check waits in the guest, so it runs before the host reports an address, which it does later.
        if ($guest->bootstrapReady($vm)) {
            $settled = $observation->address !== null ? $observation : ($provider->observe($vm) ?? $observation);
            $address = $settled->address;
            if ($address !== null) {
                if ($vm->recipe_identity !== null && $settled->imageFingerprint !== $vm->image_fingerprint) {
                    throw new TaskVmException('task_vm.image_mismatch', 'The task VM did not launch its claimed recipe image.');
                }
                if ($settled->imageFingerprint !== null) {
                    $vm->update(['image_fingerprint' => $settled->imageFingerprint]);
                }

                return $address;
            }
        }
        if (now()->getTimestamp() - $this->launchedAt > self::BootstrapMinutes * 60) {
            throw new TaskVmException(TaskVmError::BootstrapTimeout, "Cloud-init on task VM [{$vm->name}] was not done ".self::BootstrapMinutes.' minutes after launch.', 504);
        }

        return null;
    }
}

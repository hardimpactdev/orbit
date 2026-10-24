<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Infrastructure\Processes\CommandResult;
use App\Models\TaskVm;

/**
 * Creates and removes task VMs on one kind of compute host, and runs the fixed guest commands in them. Every method
 * is idempotent by `$vm->name`. The implementation validates the output of its host, and `TaskVmGuest` the output
 * of the guest commands.
 */
interface TaskVmProvider
{
    /** Create the VM with access for this Gateway public key. An existing VM with the name counts as created. */
    public function create(TaskVm $vm, string $gatewayPublicKey): void;

    /** Returns null when the VM is absent. */
    public function observe(TaskVm $vm): ?VmObservation;

    /** The `SHA256:…` fingerprint of the guest's ed25519 SSH host key, read through the trusted host. */
    public function sshHostFingerprint(TaskVm $vm): string;

    /**
     * Runs the guest command in the VM and returns its result. A guest that cannot run commands yet gives a failed
     * result with nothing on stdout.
     */
    public function exec(TaskVm $vm, TaskVmGuestCommand $command): CommandResult;

    /** Start a stopped VM. A running VM counts as started. */
    public function start(TaskVm $vm): void;

    /** Stop the VM and keep its disk. A stopped or absent VM counts as stopped. */
    public function stop(TaskVm $vm): void;

    /** Delete the VM. An absent VM counts as success. */
    public function destroy(TaskVm $vm): void;
}

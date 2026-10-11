<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Models\TaskVm;

/**
 * Creates and removes task VMs on one kind of compute host. Every method is idempotent by
 * `$vm->name`. The implementation validates host and guest output once, before it returns.
 */
interface TaskVmProvider
{
    /** Create the VM with this cloud-init user-data. An existing VM with the name counts as created. */
    public function create(TaskVm $vm, string $userData): void;

    /** Returns null when the VM is absent. */
    public function observe(TaskVm $vm): ?VmObservation;

    /** True when cloud-init finished without errors. */
    public function bootstrapReady(TaskVm $vm): bool;

    /** The `SHA256:…` fingerprint of the guest's ed25519 SSH host key, read through the trusted host. */
    public function sshHostFingerprint(TaskVm $vm): string;

    /** The manifest of the programs the VM's base image ran, read through the trusted host, or null when the image has none. */
    public function imageManifest(TaskVm $vm): ?string;

    /** Start a stopped VM. A running VM counts as started. */
    public function start(TaskVm $vm): void;

    /** Stop the VM and keep its disk. A stopped or absent VM counts as stopped. */
    public function stop(TaskVm $vm): void;

    /** Delete the VM. An absent VM counts as success. */
    public function destroy(TaskVm $vm): void;
}

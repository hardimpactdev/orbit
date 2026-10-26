<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Models\TaskVm;

/** The lifecycle and guest observations of a task VM on its selected compute provider. */
interface TaskVmProvider
{
    /** Create the VM with access for this Gateway public key. An existing VM with the name counts as created. */
    public function create(TaskVm $vm, string $gatewayPublicKey): void;

    /** Returns null when the VM is absent. */
    public function observe(TaskVm $vm): ?VmObservation;

    /** The `SHA256:…` fingerprint of the guest's ed25519 SSH host key, read through the trusted host. */
    public function sshHostFingerprint(TaskVm $vm): string;

    /** True when cloud-init completed without errors; false while the guest is still booting. */
    public function bootstrapReady(TaskVm $vm): bool;

    /** Start a stopped VM. A running VM counts as started. */
    public function start(TaskVm $vm): void;

    /** Stop the VM and keep its disk. A stopped or absent VM counts as stopped. */
    public function stop(TaskVm $vm): void;

    /** Delete the VM. An absent VM counts as success. */
    public function destroy(TaskVm $vm): void;
}

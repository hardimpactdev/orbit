<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Models\TaskVm;

/** The lifecycle and guest observations of a task VM on its selected compute provider. */
interface TaskVmProvider
{
    /** Returns null when the VM is absent. */
    public function observe(TaskVm $vm): ?VmObservation;

    /** The `SHA256:…` fingerprint of the guest's ed25519 SSH host key, read through the trusted host. */
    public function sshHostFingerprint(TaskVm $vm): string;

    /** True when cloud-init completed without errors; false while the guest is still booting. */
    public function bootstrapReady(TaskVm $vm): bool;
}

<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use SensitiveParameter;

/**
 * The model route of task VMs ([Compute drivers](/reference/compute-drivers#configure-task-vms)). The extension that runs
 * the model router binds it, so core never sees the router's management key or anchor key.
 */
interface ModelKeyIssuer
{
    /** The origin that Pi on a task VM calls, such as `http://10.44.0.3:8317`. */
    public function origin(): string;

    /** Registers a group key at the origin. A key that is already registered stays. */
    public function issue(string $origin, #[SensitiveParameter] string $key): void;

    /** Removes a group key from the origin. A key that is already gone succeeds. */
    public function revoke(string $origin, #[SensitiveParameter] string $key): void;
}

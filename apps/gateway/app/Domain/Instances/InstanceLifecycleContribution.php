<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;

/**
 * An extension's part of a container Instance's create, destroy and wake flows (docs/reference/development-containers.md#t3-registration-and-pairing).
 * The admission of a clone asks each contribution first; the create's projection hands each one the run's result, in its own
 * transaction. A contribution changes only its own records: the machine work is the run's.
 */
interface InstanceLifecycleContribution
{
    /** Refuses a clone before anything changes while the contribution cannot record its result, such as while its extension is off. */
    public function assertCreateAvailable(): void;

    /**
     * Records the clone's T3 server from the run's result: `environment_id`, `server_version`, `url`, `label`, `expires_at` and the
     * Gateway's `admin_session`, which the projection read from the vault.
     *
     * @param  array<string, mixed>  $server
     */
    public function projectCreate(Instance $instance, array $server): void;

    /** Remove extension records after native destruction, inside the projection transaction. */
    public function projectDestroy(Instance $instance): void;

    /**
     * Follow-up work after a wake succeeded, once its transaction committed: the app and T3 answer. It must not block or fail the
     * wake, and it does nothing while its extension is off.
     */
    public function projectWake(Instance $instance): void;
}

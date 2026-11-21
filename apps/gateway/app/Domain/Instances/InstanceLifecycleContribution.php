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
    /** Refuses a clone before anything changes while the contribution cannot record its result. An extension being off need not prevent recording. */
    public function assertCreateAvailable(): void;

    /**
     * Records the clone's T3 server from the run's result: `environment_id`, `server_version`, `url`, `label`, `expires_at` and the
     * Gateway's `admin_session`, which the projection read from the vault.
     *
     * @param  array<string, mixed>  $server
     */
    public function projectCreate(Instance $instance, array $server): void;

    /** Whether the contribution holds the clone's T3 server, as `projectCreate` recorded it. A completion needs it (`instance:complete`). */
    public function registered(Instance $instance): bool;

    /** Records the clone's new name after its finalize renamed it, inside that transaction: the T3 server's label is `$label`. */
    public function projectRename(Instance $instance, string $label): void;

    /** Remove extension records after native destruction, inside the projection transaction. */
    public function projectDestroy(Instance $instance): void;

    /**
     * Follow-up work after a wake succeeded, once its transaction committed: the app and T3 answer. It must not block or fail the
     * wake, and it does nothing while its extension is off. A background caller can ask to handle a queue failure as a retry.
     */
    public function projectWake(Instance $instance, bool $retryQueueFailure = false): void;
}

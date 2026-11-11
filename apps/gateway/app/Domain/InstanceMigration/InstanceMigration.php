<?php

declare(strict_types=1);

namespace App\Domain\InstanceMigration;

use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;

/**
 * The move of a checkout Instance into a container (docs/reference/development-containers.md#migrate-a-checkout-instance), while it
 * runs. Its runtime row is reserved at admission, but the checkout stays the Instance's runtime until the Route serves the
 * container: the Instance's site stays the checkout's, and it takes no deployment and no wake meanwhile. The kind is temporary
 * (docs/reference/old-paths.md).
 */
final class InstanceMigration
{
    public const string Kind = 'instance.migrate';

    public static function running(Instance $instance): bool
    {
        return OperationStore::forTarget('instance', $instance->id)->where('kind', self::Kind)
            ->whereNotIn('status', OperationStatus::terminalValues())->exists();
    }

    /**
     * Whether the Instance's last move has not reached its container: it runs, it never started, or it failed before the Route
     * served the container. Its runtime row is then only reserved, and the checkout is still what the Instance runs from.
     */
    public static function pending(Instance $instance): bool
    {
        $last = OperationStore::forTarget('instance', $instance->id)->where('kind', self::Kind)->orderByDesc('id')->first();

        return $last instanceof Operation && $last->status !== OperationStatus::Succeeded && ($last->result['moved'] ?? false) !== true;
    }

    /** A deployment needs the Instance's one runtime: it waits for a running move, and a move that did not finish runs again first. */
    public static function assertSettled(Instance $instance): void
    {
        if (self::running($instance)) {
            throw new ResourceOperationException('instance.lifecycle_busy', "Instance [{$instance->name}] is moving into a container.", 409);
        }
        if (self::pending($instance) && $instance->runtime()->exists()) {
            throw new ResourceOperationException('instance.lifecycle_busy',
                "The move of Instance [{$instance->name}] into a container did not finish. Run `orbit instance:migrate {$instance->id}` again.", 409);
        }
    }
}

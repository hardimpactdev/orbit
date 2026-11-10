<?php

declare(strict_types=1);

namespace App\Domain\InstanceMigration;

use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;

/**
 * The move of a checkout Instance into a container (docs/reference/development-containers.md#migrate-a-checkout-instance), while it
 * runs. Its runtime row is reserved at admission, but the checkout stays the Instance's runtime until the run has succeeded: the
 * Instance takes no deployment and no wake meanwhile. The kind is temporary (docs/reference/old-paths.md).
 */
final class InstanceMigration
{
    public const string Kind = 'instance.migrate';

    public static function running(Instance $instance): bool
    {
        return Operation::query()->where('kind', self::Kind)->where('target_type', 'instance')->where('target_id', $instance->id)
            ->whereNotIn('status', OperationStatus::terminalValues())->exists();
    }

    public static function assertNotRunning(Instance $instance): void
    {
        if (self::running($instance)) {
            throw new ResourceOperationException('instance.lifecycle_busy', "Instance [{$instance->name}] is moving into a container.", 409);
        }
    }
}

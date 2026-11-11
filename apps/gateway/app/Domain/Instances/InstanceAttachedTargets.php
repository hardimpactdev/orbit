<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use Illuminate\Database\Eloquent\Collection;

/** The ordered attachment inventory for Instance reads. */
final readonly class InstanceAttachedTargets
{
    public const array Relations = [
        'databaseConnectionTargets.databaseConnection.server.node',
        'databaseConnectionTargets.databaseConnection.node',
    ];

    /** @return Collection<int, DatabaseConnectionTarget> */
    public static function forInstance(Instance $instance): Collection
    {
        $instance->loadMissing(self::Relations);

        return $instance->databaseConnectionTargets
            ->sort(static fn (DatabaseConnectionTarget $left, DatabaseConnectionTarget $right): int => [$left->prefix !== 'DB', $left->id] <=> [$right->prefix !== 'DB', $right->id])
            ->values();
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;

/** Select from stored placement and ownership; callers cannot choose a writer. */
final class InstanceDestroyKind
{
    public static function forInstance(Instance $instance): string
    {
        return 'instance.destroy';
    }

    public static function forRollback(Operation $parent): string
    {
        $kind = $parent->children()->where('child_key', 'rollback-removal')->value('kind');
        if ($kind !== null) {
            if (! in_array($kind, ['instance.destroy'], true)) {
                throw new ResourceOperationException('operation.child_unproven', 'The recorded rollback kind changed.', 409);
            }

            return $kind;
        }

        return self::forInstance(Instance::query()->findOrFail($parent->target_id));
    }
}

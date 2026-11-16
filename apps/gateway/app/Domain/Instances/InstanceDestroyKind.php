<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;

/** Select from stored placement and ownership; callers cannot choose a writer. */
final class InstanceDestroyKind
{
    /** The Operation kinds that remove an Instance: they share admission, Route holds and the record projection. */
    public const array Removals = ['instance.destroy', 'instance.unregister'];

    public static function forInstance(Instance $instance): string
    {
        return 'instance.destroy';
    }

    public static function removes(string $kind): bool
    {
        return in_array($kind, self::Removals, true);
    }
}

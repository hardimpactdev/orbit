<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\DatabaseConnection;
use App\Models\Instance;

/** Select from stored placement and ownership; callers cannot choose a writer. */
final class InstanceDestroyKind
{
    public static function forInstance(Instance $instance): string
    {
        return $instance->placedOnAppDev()
            && ! DatabaseConnection::query()->where('owner_instance_id', $instance->id)->whereNotNull('database_server_id')->exists()
            ? 'instance.destroy_development' : 'instance.destroy';
    }
}

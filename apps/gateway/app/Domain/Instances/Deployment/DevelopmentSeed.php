<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Models\Instance;
use App\Models\InstanceRuntime;

/** Container snapshots own their seed; checkout Instances record it on the Instance. */
final readonly class DevelopmentSeed
{
    public static function commit(Instance $instance): ?string
    {
        $instance->loadMissing('runtime');

        return $instance->runtime instanceof InstanceRuntime ? $instance->runtime->seed_commit : $instance->seed_commit;
    }
}

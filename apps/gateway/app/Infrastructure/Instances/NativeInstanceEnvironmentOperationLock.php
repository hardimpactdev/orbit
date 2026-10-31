<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use Closure;
use Illuminate\Support\Facades\DB;

/** Serialize stored-intent mutations; native Swamp models own execution locks. */
final readonly class NativeInstanceEnvironmentOperationLock implements InstanceEnvironmentOperationLock
{
    public function run(array $instanceIds, Closure $operation): mixed
    {
        return DB::transaction($operation);
    }
}

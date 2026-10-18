<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseServer;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * One ACL change at a time on a Valkey Database server: a user create, a user delete, a close, or a repair. Another
 * change while one runs returns `database.server_in_use`. The lock outlasts a repair, which writes the ACL file,
 * restarts Valkey, and waits for it.
 */
final readonly class ValkeyAclLock
{
    private const int SECONDS = 600;

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public static function run(DatabaseServer $server, Closure $operation): mixed
    {
        $lock = Cache::lock("database-server:{$server->id}:acl", self::SECONDS);

        if (! $lock->get()) {
            throw new ResourceOperationException(
                errorCode: 'database.server_in_use',
                message: "Database server [{$server->slug}] is changing ACL users. Try again.",
                status: 409,
            );
        }

        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }
}

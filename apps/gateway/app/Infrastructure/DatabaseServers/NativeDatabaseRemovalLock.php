<?php

declare(strict_types=1);

namespace App\Infrastructure\DatabaseServers;

use App\Domain\DatabaseServers\DatabaseRemovalLock;
use App\Domain\Shared\ResourceOperationException;
use Closure;
use RuntimeException;

/** Process-owned locks on the active Gateway, released by the kernel after a crash. */
final readonly class NativeDatabaseRemovalLock implements DatabaseRemovalLock
{
    public function __construct(private string $directory) {}

    public function run(array $serverIds, Closure $operation): mixed
    {
        $ids = array_values(array_unique($serverIds));
        sort($ids, SORT_NUMERIC);
        if ($ids === []) {
            return $operation();
        }
        if (! is_dir($this->directory) && ! mkdir($this->directory, 0o700, true) && ! is_dir($this->directory)) {
            throw new RuntimeException('Could not create the database removal lock directory.');
        }
        $handles = [];
        try {
            foreach ($ids as $id) {
                $path = "{$this->directory}/server-{$id}.lock";
                $handle = fopen($path, 'c');
                if ($handle === false) {
                    throw new RuntimeException('Could not open a database removal lock.');
                }
                $handles[] = $handle;
                if (! chmod($path, 0o600)) {
                    throw new RuntimeException('Could not protect a database removal lock.');
                }
                if (! flock($handle, LOCK_EX | LOCK_NB)) {
                    throw new ResourceOperationException(
                        'database.server_in_use',
                        'Another database removal holds a server in the planned set. Retry when it finishes.',
                        409,
                    );
                }
            }

            return $operation();
        } finally {
            foreach (array_reverse($handles) as $handle) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }
}

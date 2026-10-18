<?php

declare(strict_types=1);

namespace App\Actions\DatabaseServers;

use App\Actions\Processes\DestroyProcessAction;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\DatabaseServers\ValkeyAclLock;
use App\Models\DatabaseServer;
use App\Models\Process;

/**
 * Removes a Database server that no connection uses: first its Process, through the ordinary
 * Process removal, then the record. Docker keeps the named data volume.
 */
final readonly class DestroyDatabaseServerAction
{
    public function __construct(private DestroyProcessAction $processes) {}

    public function execute(DatabaseServer $server): void
    {
        if ($server->driver === DatabaseDriver::Redis) {
            ValkeyAclLock::run($server, fn () => $this->remove($server->refresh()));

            return;
        }

        $this->remove($server);
    }

    private function remove(DatabaseServer $server): void
    {
        if ($server->databaseConnections()->exists()) {
            throw new ResourceOperationException(
                errorCode: 'database.server_in_use',
                message: "Database server [{$server->slug}] still holds databases. Destroy their connections first.",
                status: 409,
            );
        }

        $process = $server->process;

        if ($process instanceof Process) {
            $this->processes->execute($process, removedByOwningRole: true);
        }

        $server->delete();
    }
}

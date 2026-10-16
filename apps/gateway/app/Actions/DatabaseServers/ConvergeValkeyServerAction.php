<?php

declare(strict_types=1);

namespace App\Actions\DatabaseServers;

use App\Actions\Processes\RestartProcessAction;
use App\Domain\DatabaseServers\DatabaseServerProcess;
use App\Domain\DatabaseServers\ValkeyAcl;
use App\Domain\DatabaseServers\ValkeyAclLock;
use App\Domain\DatabaseServers\ValkeyServerAdmin;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseServer;
use App\Models\Process;

/**
 * Makes an active Valkey Database server match its records again; a repeat of `database:server:create` runs it.
 *
 * When `default` cannot log in with the admin password, or Valkey runs without Orbit's ACL file, Valkey cannot be
 * repaired through its own commands: the action writes the ACL file from the records, restarts the Process, and waits
 * for the admin login. Then it sets each recorded user that is missing or differs, deletes every other user, and runs
 * `ACL SAVE`. A server that matches gets only the save, so a repeat is safe.
 */
final readonly class ConvergeValkeyServerAction
{
    public function __construct(
        private ValkeyServerAdmin $admin,
        private WriteValkeyAclFileAction $aclFile,
        private RestartProcessAction $restart,
    ) {}

    public function execute(DatabaseServer $server): void
    {
        ValkeyAclLock::run($server, function () use ($server): void {
            $server->refresh()->load(['node', 'process', 'databaseConnections.users']);

            if (! $this->admin->acceptsAdminLogin($server) || $this->admin->aclFile($server) !== DatabaseServerProcess::VALKEY_ACL_FILE) {
                $this->rebuild($server);
            }

            $changes = ValkeyAcl::changes($server, $this->admin->aclUsers($server));
            $this->admin->applyUsers($server, $changes['set'], $changes['delete']);
        });
    }

    /** Write the ACL file from the records and start Valkey from it again. */
    private function rebuild(DatabaseServer $server): void
    {
        $process = $server->process;
        $volume = $process instanceof Process ? DatabaseServerProcess::valkeyVolume($process) : null;

        if (! $process instanceof Process || $volume === null) {
            throw new ResourceOperationException(
                errorCode: 'database.server_command_failed',
                message: "Database server [{$server->slug}] has no Process with a volume at /data.",
                status: 502,
            );
        }

        $this->aclFile->execute($server, $volume);
        $this->restart->execute($process);

        if (! $this->admin->waitUntilReady($server, CreateDatabaseServerAction::READY_TIMEOUT_SECONDS)) {
            throw CreateDatabaseServerAction::startFailed($server);
        }
    }
}

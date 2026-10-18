<?php

declare(strict_types=1);

namespace App\Actions\DatabaseServers;

use App\Domain\DatabaseServers\DatabaseServerProcess;
use App\Domain\DatabaseServers\ValkeyAcl;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\RemotePrograms\RemoteProgramFailed;
use App\Domain\RemotePrograms\RemoteProgramRunner;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;
use App\Models\DatabaseServer;

/**
 * Writes `/data/users.acl` into the volume of a Valkey Database server with the remote program, from the recorded
 * users ({@see ValkeyAcl::file()}). The file holds only password hashes. A failure keeps no remote output.
 */
final readonly class WriteValkeyAclFileAction
{
    public const string PROGRAM = 'database-servers.write-valkey-acl';

    public function __construct(
        private RemoteProgramRunner $programs,
        private RemoteProgramCatalog $catalog,
    ) {}

    public function execute(DatabaseServer $server, string $volume): void
    {
        try {
            $result = $this->programs->run($server->node, $this->catalog->program(self::PROGRAM), [
                'volume' => $volume,
                'image' => DatabaseServerProcess::valkeyImage($server->tag),
                'acl' => ValkeyAcl::file($server),
            ]);
        } catch (RemoteProgramFailed $exception) {
            throw $this->failed($server, $exception->exitCode ?? 1);
        }

        if ($result->outcome !== 'written') {
            throw $this->failed($server, 1);
        }
    }

    private function failed(DatabaseServer $server, int $exitCode): ProcessOperationException
    {
        return new ProcessOperationException(
            step: 'acl_file',
            errorCode: 'database.server_command_failed',
            message: "Database server [{$server->slug}] could not write its ACL file.",
            result: new CommandResult($exitCode, '', 'ACL file write failed.', 0, false),
        );
    }
}

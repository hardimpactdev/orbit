<?php

declare(strict_types=1);

namespace App\Actions\DatabaseServers;

use App\Domain\DatabaseServers\DatabaseServerProcess;
use App\Domain\DatabaseServers\ValkeyAcl;
use App\Domain\Processes\ProcessOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Processes\CommandResult;
use App\Models\DatabaseServer;

/**
 * Writes `/data/users.acl` into the volume of a Valkey Database server with the agent's `databases.write-valkey-acl`, from the recorded
 * users ({@see ValkeyAcl::file()}). The file holds only password hashes. A failure keeps no remote output.
 */
final readonly class WriteValkeyAclFileAction
{
    public function __construct(
        private AgentCommandRunner $agents,
    ) {}

    public function execute(DatabaseServer $server, string $volume): void
    {
        try {
            $this->agents->run($server->node, 'databases.write-valkey-acl', [
                'volume' => $volume,
                'image' => DatabaseServerProcess::valkeyImage($server->tag),
                'acl' => ValkeyAcl::file($server),
            ]);
        } catch (AgentCommandRefused $refusal) {
            $exitCode = $refusal->details['exit_code'] ?? 1;
            throw $this->failed($server, is_int($exitCode) ? $exitCode : 1);
        } catch (AgentRunnerFailure $failure) {
            throw $this->failed($server, $failure->result instanceof CommandResult ? $failure->result->exitCode : 1);
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

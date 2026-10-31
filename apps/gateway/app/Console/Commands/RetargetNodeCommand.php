<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Nodes\RetargetNodeAction;
use App\Data\Nodes\RetargetNodeData;
use App\Domain\Shared\ResourceOperationException;
use Illuminate\Console\Command;

final class RetargetNodeCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:node-retarget {name : Existing node name} {host : New public SSH host} {--ssh-port=22 : Public SSH port}';

    #[\Override]
    protected $description = 'Retarget an existing provisioned node to a new public SSH host.';

    public function handle(RetargetNodeAction $action): int
    {
        $name = $this->input->getArgument('name');
        $host = $this->input->getArgument('host');
        $port = $this->option('ssh-port');

        if (! is_string($name) || ! is_string($host) || ! is_numeric($port) || (int) $port < 1 || (int) $port > 65535) {
            $this->error('Node retarget arguments are invalid.');

            return self::FAILURE;
        }

        try {
            $operation = $action->execute(new RetargetNodeData($name, $host, (int) $port));
        } catch (ResourceOperationException $exception) {
            $this->error("Node retarget failed with error [{$exception->errorCode}].");

            return self::FAILURE;
        }

        $this->info("Node retarget Operation [{$operation->id}] was admitted.");

        return self::SUCCESS;
    }
}

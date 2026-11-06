<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Nodes\StartNodeConvergeAction;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use Illuminate\Console\Command;

/**
 * Admits `node.converge` from the Gateway itself, the way `node:converge` does through the API. The Gateway of a fleet build
 * converges its own Node this way, which installs the agent and the CLI on its machine (docs/cli/tasks/vm-fleet-build.mmd).
 */
final class NodeConvergeCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:node-converge
        {node : Node name}';

    #[\Override]
    protected $description = 'Admit node.converge for a Node from the Gateway itself.';

    public function handle(StartNodeConvergeAction $converge): int
    {
        $node = Node::query()->where('name', (string) $this->argument('node'))->first();
        if (! $node instanceof Node) {
            $this->error('The Node is unknown.');

            return self::FAILURE;
        }

        try {
            $operation = $converge->execute($node);
        } catch (ResourceOperationException $exception) {
            $this->error("{$exception->errorCode}: {$exception->getMessage()}");

            return self::FAILURE;
        }
        $this->line("Operation #{$operation->id} {$operation->kind} {$operation->status->value}");

        return self::SUCCESS;
    }
}

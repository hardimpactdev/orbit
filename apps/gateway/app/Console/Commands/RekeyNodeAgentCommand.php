<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Nodes\RekeyNodeAgentAction;
use App\Domain\Shared\ResourceOperationException;
use Illuminate\Console\Command;
use Throwable;

final class RekeyNodeAgentCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:node-agent-rekey {node : Owning Node ID} {operation : Uncertain node.converge Operation ID}';

    #[\Override]
    protected $description = 'Restore agent authentication after a lost Swamp configure reply.';

    public function handle(RekeyNodeAgentAction $action): int
    {
        $node = filter_var($this->argument('node'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $operation = filter_var($this->argument('operation'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($node === false || $operation === false) {
            $this->error('Supply the owning Node ID and Operation ID.');

            return self::FAILURE;
        }
        try {
            $recovered = $action->execute($node, $operation);
        } catch (ResourceOperationException $exception) {
            $this->error('Agent re-key refused: '.$exception->errorCode.'.');

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Agent re-key did not return a proven result. Retry this operator command.');

            return self::FAILURE;
        }
        $this->info("Agent authentication restored for Node [{$recovered->name}] (Operation #{$operation}).");
        $this->line('The original configure receipt remains uncertain. Cancel that Operation, then start a fresh converge.');

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Nodes\ConvergeNodeWorkerAction;
use App\Models\Node;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Throwable;

final class ConvergeNodeWorkersCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:node-workers';

    #[\Override]
    protected $description = 'Converge the operator-selected Node workers and rotate due 24-hour credentials.';

    public function handle(ConvergeNodeWorkerAction $action): int
    {
        $failed = false;
        foreach (Node::query()->whereIn('id', Config::array('swamp_workers.nodes'))->orderBy('id')->get() as $node) {
            try {
                $action->execute($node);
            } catch (Throwable) {
                $this->error('Node '.$node->id.': worker lifecycle unavailable.');
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}

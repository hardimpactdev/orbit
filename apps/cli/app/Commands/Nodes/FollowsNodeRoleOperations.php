<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Commands\Concerns\FollowsOperations;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Responses\Operations\OperationResponse;

/** @phpstan-require-extends NodeCommand */
trait FollowsNodeRoleOperations
{
    use FollowsOperations { writeOperationSummary as private operationSummary; }

    private function writeOperationSummary(OperationResponse $operation): void
    {
        $this->operationSummary($operation);
        $followUp = $operation->result['follow_up'] ?? null;
        if (is_string($followUp)) {
            ConsoleWriter::write($this->output, NodeOutput::followUpWarning($this->consoleMode(), $followUp));
        }
    }
}

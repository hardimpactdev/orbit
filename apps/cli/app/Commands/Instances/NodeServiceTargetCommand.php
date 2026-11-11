<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\GatewayCommand;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Responses\Instances\InstanceNodeServiceTargetResponse;

abstract class NodeServiceTargetCommand extends GatewayCommand
{
    protected function renderTarget(InstanceNodeServiceTargetResponse $target, string $operation): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($target->toArray());

            return self::SUCCESS;
        }
        $title = $target->changed ? "Node service target {$operation}." : 'Skipped — nothing to change (instance.node_service_target_unchanged).';
        ConsoleWriter::write($this->output, $this->humanRenderer()->detail($title, [
            'Target ID' => $target->id, 'Instance ID' => $target->instanceId, 'Node ID' => $target->nodeId, 'Process ID' => $target->processId,
            'Protocol' => $target->protocol, 'Port' => $target->port, 'Host key' => $target->hostEnvKey,
            'Port key' => $target->portEnvKey, 'Changed' => $target->changed ? 'true' : 'false',
            'Request ID' => $target->requestId,
        ]));

        return self::SUCCESS;
    }
}

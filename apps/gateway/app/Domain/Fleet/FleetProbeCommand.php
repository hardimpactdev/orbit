<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Operations\OperationGatewayCommand;
use App\Models\Node;
use App\Models\Operation;

/** A read after transport loss. It does not retry or infer an effect's outcome. */
final readonly class FleetProbeCommand implements OperationGatewayCommand
{
    public function __construct(private NodeReachabilityProbe $probe) {}

    public function schema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public function run(Operation $operation, #[\SensitiveParameter] array $input): array
    {
        return ['reachable' => $this->probe->degradation(Node::query()->findOrFail($operation->node_id)) !== ExporterDegradationReason::Unreachable];
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Records the hub run's public outcome; the run replaced the peers. */
final readonly class NodeWireGuardHubProjection implements DirectOperationProjection
{
    public function input(Operation $operation): array
    {
        return ['intent' => ValidatedData::object($operation->input['intent']), 'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        // The run ends on its own check; a success means the hub runs the admitted peer list.
        return app(DirectOperation::class)->project($operation, $data,
            ($data['status'] ?? null) === 'succeeded' ? ['projected' => true] : []);
    }
}

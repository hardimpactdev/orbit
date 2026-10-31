<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Models\Operation;
use App\Support\ValidatedData;

/** Public network intent for the shared workflow_inputs path. No runtime reads or secret hydration. */
final readonly class NodeNetworkCommandInput
{
    /** @return array{intent:array<string,mixed>,execution_secrets:array<string,mixed>} */
    public function capture(Operation $operation): array
    {
        return ['intent' => ['schema_version' => 1, 'node_id' => $operation->node_id,
            'participant_node_ids' => ValidatedData::integerList($operation->input['participant_node_ids'] ?? []),
            'network' => ValidatedData::object($operation->input['_network'] ?? []),
            'hub' => ValidatedData::object($operation->input['_wireguard'] ?? [])],
            'execution_secrets' => ValidatedData::object($operation->input['execution_secrets'] ?? [])];
    }
}

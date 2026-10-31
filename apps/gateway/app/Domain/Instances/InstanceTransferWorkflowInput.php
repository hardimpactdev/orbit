<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Processes\ProcessInstallationBatch;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Full public admission snapshot. Execution-copy descriptors travel outside intent. */
final readonly class InstanceTransferWorkflowInput
{
    public function __construct(private ProcessInstallationBatch $processes) {}

    /** @return array<string,mixed> */
    public function input(Operation $operation): array
    {
        $plan = ValidatedData::object($operation->input['direct_transfer']);
        $batches = ValidatedData::object($operation->input['process_installations']);
        $runtime = [];
        foreach (['source', 'destination'] as $side) {
            $batch = ValidatedData::object($batches[$side.'_installations']);
            $runtime[$side] = ['node_id' => ValidatedData::integer($batch['node_id']),
                'members' => $this->processes->input($batch)];
        }

        return [
            'direct_transfer' => [
                ...Arr::only($plan, ['transfer_id', 'instance_id', 'source_node_id', 'destination_node_id', 'cutover', 'completed']),
                'source' => [
                    ...Arr::only(ValidatedData::object($plan['source']), ['source', 'user', 'layout', 'repository_identity']),
                    'branch' => ValidatedData::nullableString(ValidatedData::object($plan['source'])['branch']) ?? '',
                ],
                'destination' => Arr::only(ValidatedData::object($plan['destination']), ['destination', 'root', 'environment', 'user']),
            ],
            'process_runtime' => $runtime,
            'route_transfer' => Arr::only(ValidatedData::object($operation->input['route_transfer']), [
                'transfer_id', 'instance_id', 'source_route_id', 'destination_route_id', 'source_node_id', 'destination_node_id',
                'source_router_node_id', 'destination_router_node_id', 'destination_path', 'destination_placement',
                'routes', 'retire_nodes', 'participant_node_ids', 'target',
            ]),
        ];
    }
}

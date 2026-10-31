<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\InstanceRemoval;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Complete public input for the native run. No worker fetches the Operation. */
final readonly class InstanceDestroyWorkflowInput
{
    /** @return array<string, mixed> */
    public function forOperation(Operation $operation): array
    {
        $input = $operation->input;
        $detail = ValidatedData::object($input['direct_destroy']);
        $detail['routes'] = array_map(static function (array $route): array {
            unset($route['php']);

            return $route;
        }, InstanceLifecycleInput::members(['members' => $detail['routes']]));
        $removal = InstanceRemoval::query()->findOrFail(ValidatedData::string($input['removal_id']));

        return [
            'operation_id' => $operation->id,
            'kind' => $operation->kind,
            'node_id' => $operation->node_id,
            'intent' => [
                ...array_intersect_key($input, array_flip([
                    'instance_id', 'project_id', 'removal_id', 'node_id', 'environment', 'force',
                    'repository', 'repository_identity', 'protected_source_roots', 'members',
                    'participant_node_ids', 'parent_owns_routes',
                ])),
                'direct_destroy' => $detail,
                'captured_sources' => $removal->native_sources,
            ],
        ];
    }
}

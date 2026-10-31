<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Models\Operation;
use App\Support\ValidatedData;

/** Safe immutable intent. Native Swamp owns runtime checks and execution. */
final readonly class InstanceDeploymentDirectInput
{
    /** @return array<string,mixed> */
    public function input(Operation $operation): array
    {
        return $this->admitted($operation);
    }

    /** @return array<string,mixed> */
    public function admitted(Operation $operation): array
    {
        $input = $operation->input;
        $deployment = ValidatedData::object($input['deployment']);

        return ['instance_id' => $operation->target_id, 'project_id' => ValidatedData::integer($deployment['project_id']),
            'node_id' => $operation->node_id, 'platform' => ValidatedData::string($input['platform']),
            'architecture' => ValidatedData::string($input['architecture']), 'deployment' => $deployment,
            'commit' => InstanceDeploymentIntent::commit($operation), 'release_name' => InstanceDeploymentIntent::releaseName($operation),
            ...array_intersect_key($input, array_flip(['environment', 'php_input', 'runtime_definitions']))];
    }
}

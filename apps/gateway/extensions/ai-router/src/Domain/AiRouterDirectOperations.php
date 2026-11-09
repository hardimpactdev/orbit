<?php

declare(strict_types=1);

namespace Orbit\Extensions\AiRouter\Domain;

use App\Domain\Operations\DirectOperationContribution;

final readonly class AiRouterDirectOperations implements DirectOperationContribution
{
    public function definitions(): array
    {
        return ['ai_router.task_guard' => [
            'workflow' => 'operation-ai-router-task-guard',
            'projection' => AiRouterTaskGuardProjection::class,
            'workflow_inputs' => true,
            // The phase ends with its checks: on the Node and from the swamp Node, a source outside the task VM range is refused.
            'terminal_result' => ['job' => 'guard', 'step' => 'Apply the guard', 'native' => true],
            'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^ai-router-(task|anchor)-key-node-[1-9][0-9]*$']],
        ], AiRouterModelForwarder::Kind => [
            'workflow' => 'operation-ai-router-model-forwarder',
            'projection' => AiRouterTaskGuardProjection::class,
            'workflow_inputs' => true,
            // The forwarder phase ends with its checks from the agent Node: from the bridge, through the forwarder, the guard answers 401
            // to no key and a direct packet to the guard is dropped; or, after a removal, the guard port does not answer the Node.
            'terminal_result' => ['job' => 'forwarder', 'step' => 'Apply the forwarder', 'native' => true],
            'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^ai-router-(task|anchor)-key-node-[1-9][0-9]*$']],
        ]];
    }
}

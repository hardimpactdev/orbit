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
            // The end check: from the swamp Node, a WireGuard member outside the task VM range, port 8318 does not answer.
            'terminal_result' => ['job' => 'guard', 'step' => 'Check from outside the task VM range', 'native' => true],
            'execution_copies' => [['vault' => 'orbit-control-inputs', 'key_pattern' => '^ai-router-(task|anchor)-key-node-[1-9][0-9]*$']],
        ]];
    }
}

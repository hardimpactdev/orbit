<?php

declare(strict_types=1);

use App\Domain\Proof\ProofCommandProjection;
use App\Domain\Proof\ProofVerifyProjection;
use App\Domain\Tasks\TaskGitHubTokens;

$provision = (require __DIR__.'/../Tasks/direct-operations.php')['tasks.provision'];
$destroy = (require __DIR__.'/../TaskVms/direct-operations.php')['task_vm.destroy'];

return [
    // The Swamp workflow `proof-fleet-verify` proves one command on the task VM that calls, with the fleet it already has. Its `report` step
    // joins the verdict lines; the Gateway reads it once, when the run ends.
    'proof.verify' => ['workflow' => 'proof-fleet-verify', 'projection' => ProofVerifyProjection::class, 'workflow_inputs' => true,
        'terminal_result' => ['job' => 'report', 'step' => 'report', 'native' => true]],
    // The Swamp workflow `operation-proof-run` proves one command on its own task VM: the provision, the cases through `proof-fleet-verify`
    // and the destruction of the VM, always. Its `Report` step joins the verdict lines; the Gateway reads it once, when the run ends.
    'proof.command' => ['workflow' => 'operation-proof-run', 'projection' => ProofCommandProjection::class, 'workflow_inputs' => true, 'github_token_issuer' => TaskGitHubTokens::class,
        'terminal_result' => ['job' => 'prove', 'step' => 'Report', 'native' => true, 'captures' => ['provision' => ['job' => 'prove', 'step' => 'Create the VM']]],
        'execution_copies' => [...$provision['execution_copies'], ...$destroy['execution_copies']]],
];

<?php

declare(strict_types=1);

use App\Domain\Proof\ProofCommandProjection;
use App\Domain\Tasks\TaskGitHubTokens;

$provision = (require __DIR__.'/../Tasks/direct-operations.php')['tasks.provision'];
$destroy = (require __DIR__.'/../TaskVms/direct-operations.php')['task_vm.destroy'];

// The Swamp workflow `operation-proof-run` proves one command on its own task VM: the provision, the cases through `proof-fleet-verify`
// and the destruction of the VM, always. Its `Report` step joins the verdict lines; the Gateway reads it once, when the run ends.
return ['proof.command' => ['workflow' => 'operation-proof-run', 'projection' => ProofCommandProjection::class, 'workflow_inputs' => true, 'github_token_issuer' => TaskGitHubTokens::class,
    'terminal_result' => ['job' => 'prove', 'step' => 'Report', 'native' => true, 'captures' => ['provision' => ['job' => 'prove', 'step' => 'Create the VM']]],
    'execution_copies' => [...$provision['execution_copies'], ...$destroy['execution_copies']]]];

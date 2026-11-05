<?php

declare(strict_types=1);

use App\Domain\Proof\ProofRunProjection;
use App\Domain\Proof\ProofVerifyProjection;

// The Swamp workflow `operation-proof-run` carries one proof: it provisions the task VM, resets the nested fleet, runs `proof-fleet-verify`
// for each selected command and destroys the VM. Its `report-N` steps hold the verdict lines of command N, one step each so that each list has
// its own 4,096 bytes of output; the Gateway reads them once, when the run ends, and the run's own step statuses tell whether the VM is gone.
$tasks = require __DIR__.'/../Tasks/direct-operations.php';
$reports = [];
foreach (range(0, ProofRunProjection::MaximumCommands - 1) as $index) {
    $reports['report_'.$index] = ['job' => 'report', 'step' => 'report-'.$index, 'field' => 'stdout'];
}

// The Swamp workflow `proof-fleet-verify` also runs the cases of one command on its own, when the Gateway admits it as `proof.verify`. Its
// `report` step joins the one-line verdicts; the Gateway reads that step once, when the run ends.
return ['proof.run' => ['workflow' => 'operation-proof-run', 'projection' => ProofRunProjection::class, 'workflow_inputs' => true,
    'terminal_result' => ['job' => 'report', 'step' => 'report-0', 'native' => true, 'captures' => $reports],
    'execution_copies' => [...$tasks['tasks.provision']['execution_copies'], ...$tasks['tasks.cancel']['execution_copies']]],
    'proof.verify' => ['workflow' => 'proof-fleet-verify', 'projection' => ProofVerifyProjection::class, 'workflow_inputs' => true,
        'terminal_result' => ['job' => 'report', 'step' => 'report', 'native' => true, 'captures' => ['report' => ['job' => 'report', 'step' => 'report', 'field' => 'stdout']]]]];

<?php

declare(strict_types=1);

use App\Domain\Proof\ProofVerifyProjection;

// The Swamp workflow `proof-fleet-verify` runs the cases of one command in the task VM over official SSH. Its `report` step
// joins the one-line verdicts; the Gateway reads that step once, when the run ends.
return ['proof.verify' => ['workflow' => 'proof-fleet-verify', 'projection' => ProofVerifyProjection::class, 'workflow_inputs' => true,
    'terminal_result' => ['job' => 'report', 'step' => 'report', 'native' => true, 'captures' => ['report' => ['job' => 'report', 'step' => 'report', 'field' => 'stdout']]]]];

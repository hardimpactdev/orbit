<?php

declare(strict_types=1);

use App\Domain\Proof\ProofRunProjection;

return [
    'proof.run' => [
        'workflow' => 'operation-proof-run',
        'workflow_inputs' => true,
        // No result step: the projector reads the case step's one JSON line and the run's status; the native assert is the end check.
        'terminal_result' => ['job' => 'prove', 'step' => ProofRunProjection::EndCheck, 'native' => true],
        'projection' => ProofRunProjection::class,
    ],
];

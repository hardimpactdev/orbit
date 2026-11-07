<?php

declare(strict_types=1);

/*
 * `orbit proof:run`: proofs of a commit in a disposable test fleet on a VM. App\Domain\Proof\ProofSettings
 * reads these values; every default lives here.
 */
return [
    // Whether a selected proof starts a run. On by default, as in production. Set ORBIT_PROOF_RUN_EXECUTION=false (or off, no, 0) and
    // proof:run checks the commit and selects the cases, then refuses with proof.execution_disabled and starts nothing.
    'execution' => filter_var(env('ORBIT_PROOF_RUN_EXECUTION', true), FILTER_VALIDATE_BOOL),
    // The Project whose task group carries a proof: the Orbit repository.
    'project' => 46,
    // The repository whose commits Orbit proves, as owner/name.
    'repository' => 'hardimpactdev/orbit',
    // The branch whose merge base starts a commit's diff.
    'base_branch' => 'main',
    // The pull request label that lets Orbit prove an approved pull request from outside the repository.
    'label' => 'run-proofs',
];

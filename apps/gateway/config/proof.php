<?php

declare(strict_types=1);

/*
 * `orbit proof:run`: proofs of a commit in the inner fleets of copies of the proof Project's default Instance. App\Domain\Proof\ProofSettings
 * reads these values; every default lives here.
 */
return [
    // Whether a selected proof starts a run. On by default, as in production. Set ORBIT_PROOF_RUN_EXECUTION=false (or off, no, 0) and
    // proof:run checks the commit and selects the cases, then refuses with proof.execution_disabled and starts nothing.
    'execution' => filter_var(env('ORBIT_PROOF_RUN_EXECUTION', true), FILTER_VALIDATE_BOOL),
    // The Project whose task groups carry a proof: the nested-fleet Project of the Orbit container. Each run is a copy of its default.
    'project' => 78,
    // One copy's peak memory in bytes, with room above the measured peak. A copy goes on a Node only when the Node's MemAvailable
    // holds it (docs/cli/proof/run.mdx#the-run); the free CI places of each Node count the rest.
    'copy_memory' => 8 * 1024 ** 3,
    // The warm proof copies across all CI Nodes (docs/cli/proof/run.mdx#warm-copies): copies whose create has ended, which a proof takes
    // first. Each holds a CI place, a task copy slot and a copy's memory peak while it waits. 0 turns the pool off.
    'warm_copies' => 2,
    // The repository whose commits Orbit proves, as owner/name.
    'repository' => 'hardimpactdev/orbit',
    // The branch whose merge base starts a commit's diff.
    'base_branch' => 'main',
    // The pull request label that lets Orbit prove an approved pull request from outside the repository.
    'label' => 'run-proofs',
];

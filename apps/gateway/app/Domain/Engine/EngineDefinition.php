<?php

declare(strict_types=1);

namespace App\Domain\Engine;

use App\Models\Operation;

/**
 * What one Operation kind contributes to the state machine (ADR 0271; docs/reference/gateway-engine.md): its steps, each
 * step's typed input, its Gateway steps, and its end. Orbit's knowledge stays here, in PHP; the executor only runs
 * primitives.
 */
interface EngineDefinition
{
    /**
     * The Operation's steps, from its admitted intent.
     *
     * @return list<StepSpec>
     */
    public function steps(Operation $operation): array;

    /**
     * A Node step's typed input, computed when the step is sent from the intent and the results of earlier steps.
     *
     * @return array<string, mixed>
     */
    public function input(Operation $operation, string $step, EngineRun $run): array;

    /** Runs a Gateway step and returns its output. A thrown exception fails the step. */
    public function local(Operation $operation, string $step, EngineRun $run): string;

    /** Records the Operation's end through the kind's own projection. */
    public function finish(Operation $operation, EngineRun $run): void;
}

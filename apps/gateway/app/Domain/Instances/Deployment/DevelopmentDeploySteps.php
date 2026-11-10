<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\Projects\ProjectDevelopmentDeployStepStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Operation;
use App\Models\Project;
use App\Support\ValidatedData;

/**
 * A Project's development deploy steps as an admission freezes them: names, timeouts and command digests, never the commands. When
 * the run starts, each command goes to an Operation-scoped vault key, and only if the list still matches what was admitted.
 */
final readonly class DevelopmentDeploySteps
{
    public function __construct(private ProjectDevelopmentDeployStepStore $steps, private SwampCommandStep $copies) {}

    /** @return list<array{name:string,phase:string,required:bool,timeout_seconds:int,command_digest:string}> */
    public function capture(Project $project): array
    {
        return array_map(static fn (DevelopmentDeployStep $step): array => [
            'name' => $step->name, 'phase' => 'before_activation', 'required' => $step->required,
            'timeout_seconds' => $step->timeoutSeconds, 'command_digest' => hash('sha256', $step->command),
        ], $this->steps->ordered($project));
    }

    /**
     * The vault copies `step_<index>` of the admitted list, under `development-deploy-operation-<id>-step-<index>`.
     *
     * @return array<string,array{vault:string,key:string}>
     */
    public function copies(Operation $operation, Project $project, mixed $captured): array
    {
        if (! is_array($captured) || ! array_is_list($captured)
            || array_map(static fn (mixed $step): array => ValidatedData::object($step), $captured) !== $this->capture($project)) {
            throw $this->changed();
        }
        $copies = [];
        foreach ($this->steps->ordered($project) as $index => $command) {
            $copies['step_'.$index] = $this->copies->executionCopy('orbit-control-inputs', 'development-deploy-operation-'.$operation->id.'-step-'.$index, $command->command);
        }

        return $copies;
    }

    private function changed(): ResourceOperationException
    {
        return new ResourceOperationException('operation.execution_forbidden', 'The development deployment no longer matches its admitted owner.', 403);
    }
}

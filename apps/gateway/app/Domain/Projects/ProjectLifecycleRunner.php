<?php

declare(strict_types=1);

namespace App\Domain\Projects;

use App\Actions\Instances\StartInstanceStepListAction;
use App\Models\Instance;

/** Retained Gateway caller adapter. Swamp executes every list command. */
final readonly class ProjectLifecycleRunner
{
    public function __construct(private StartInstanceStepListAction $admission) {}

    public function run(Instance $instance, LifecyclePhase $phase): bool
    {
        if ($instance->placedOnAppProd()) {
            return false;
        }
        $operation = $this->admission->retained($instance, $phase, 'orbit:project-lifecycle');
        $finished = $this->admission->await($operation);

        return ($finished->result['no_op'] ?? false) !== true;
    }
}

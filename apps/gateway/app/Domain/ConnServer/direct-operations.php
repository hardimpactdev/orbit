<?php

declare(strict_types=1);

use App\Domain\ConnServer\ConnServerRollout;
use App\Domain\ConnServer\ConnServerRolloutProjection;

return [
    // The rollout reports through its own result step; the run takes no secret.
    ConnServerRollout::Kind => ['workflow' => 'operation-fleet-conn-server-rollout', 'projection' => ConnServerRolloutProjection::class,
        'workflow_inputs' => true, 'terminal_result' => ['job' => 'rollout', 'step' => 'result']],
];

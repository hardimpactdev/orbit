<?php

declare(strict_types=1);

use App\Domain\InstanceRuntime\InstanceRuntimeUpdate;

return [
    'instance.runtime_update' => ['workflow' => 'operation-instance-runtime-update', 'workflow_inputs' => true,
        'projection' => InstanceRuntimeUpdate::class,
        'terminal_result' => ['job' => 'runtime', 'step' => 'result']],
];

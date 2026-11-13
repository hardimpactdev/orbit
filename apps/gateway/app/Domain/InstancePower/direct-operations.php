<?php

declare(strict_types=1);

use App\Domain\Instances\Power\InstanceSleep;
use App\Domain\Instances\Power\InstanceWake;

return [
    'instance.sleep' => ['workflow' => 'operation-instance-sleep', 'workflow_inputs' => true,
        'projection' => InstanceSleep::class,
        'terminal_result' => ['job' => 'sleep', 'step' => 'result']],
    'instance.wake' => ['workflow' => 'operation-instance-wake', 'workflow_inputs' => true,
        'projection' => InstanceWake::class,
        'terminal_result' => ['job' => 'wake', 'step' => 'result']],
];

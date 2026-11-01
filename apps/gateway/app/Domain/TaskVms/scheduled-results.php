<?php

declare(strict_types=1);

use App\Domain\TaskVms\ScheduledTaskVmReaper;

return [ScheduledTaskVmReaper::Workflow => [
    'workflow' => 'task-vm-reaper', 'publishes_inputs' => true,
    'terminal_result' => ['job' => 'select', 'step' => 'result'],
    'projection' => ScheduledTaskVmReaper::class,
]];

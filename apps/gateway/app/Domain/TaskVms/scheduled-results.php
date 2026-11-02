<?php

declare(strict_types=1);

use App\Domain\TaskVms\ImageReaper;

return [ImageReaper::Workflow => ['workflow' => 'task-vm-image-reaper', 'publishes_inputs' => true,
    'terminal_result' => ['job' => 'result', 'step' => 'check', 'native' => true], 'projection' => ImageReaper::class]];

<?php

declare(strict_types=1);

use App\Domain\Projects\ScheduledProjectMaintenance;

return [ScheduledProjectMaintenance::Workflow => ['workflow' => 'maintenance-projects', 'publishes_inputs' => true, 'terminal_result' => ['job' => 'maintenance', 'step' => 'tick', 'native' => true, 'captures' => ['tick_json' => ['job' => 'maintenance', 'step' => 'tick', 'field' => 'result_json']]], 'projection' => ScheduledProjectMaintenance::class]];

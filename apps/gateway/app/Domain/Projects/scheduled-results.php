<?php

declare(strict_types=1);

use App\Domain\Projects\ScheduledProjectMaintenance;

return [ScheduledProjectMaintenance::Workflow => ['workflow' => 'maintenance-projects', 'publishes_inputs' => true, 'terminal_result' => ['job' => 'maintenance', 'step' => 'select', 'native' => true, 'captures' => ['selection_json' => ['job' => 'maintenance', 'step' => 'select', 'field' => 'result_json']]], 'projection' => ScheduledProjectMaintenance::class]];

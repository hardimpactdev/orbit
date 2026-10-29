<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;
use App\Domain\Schedules\ScheduleOperationCompletion;
use App\Domain\Schedules\ScheduleOperationExecution;

return (static function (): array {
    if (! OperationKinds::supports('receipts')) {
        return [];
    }
    $kinds = [];
    foreach (['create' => 'install', 'destroy' => 'remove', 'enable' => 'activate', 'run' => 'run'] as $verb => $command) {
        $fields = ScheduleOperationExecution::fields($verb);
        $run = ['transport' => 'agent', 'name' => 'schedule.'.$command, 'resumable' => $verb !== 'run', 'input_fields' => $fields, 'input_values' => []];
        if ($verb === 'create') {
            $run['secrets'] = ['command' => 'schedule.command'];
        }
        $kinds['schedule.'.$verb] = ['executor' => 'swamp', 'completion' => ScheduleOperationCompletion::class, 'execution' => ScheduleOperationExecution::class,
            'runs' => [$run],
            'completion_contract' => ['step' => 'schedule-'.$command, 'transport' => 'agent', 'name' => 'schedule.'.$command, 'result' => []],
            'result_fields' => ['error_code']];
    }

    return $kinds;
})();

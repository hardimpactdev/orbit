<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;
use App\Domain\Schedules\ScheduleInstanceOperation;
use App\Domain\Schedules\ScheduleOperationCompletion;
use App\Domain\Schedules\ScheduleOperationExecution;
use App\Domain\Schedules\ScheduleRuntimeChildAdmission;

return (static function (): array {
    if (! OperationKinds::supports('receipts')) {
        return [];
    }
    $kinds = [];
    foreach (['create' => 'install', 'destroy' => 'remove', 'enable' => 'activate'] as $verb => $command) {
        $fields = ScheduleOperationExecution::fields($verb);
        $run = ['transport' => 'agent', 'name' => 'schedule.'.$command, 'resumable' => true, 'input_fields' => $fields, 'input_values' => []];
        if ($verb === 'create') {
            $run['secrets'] = ['command' => 'schedule.command'];
        }
        $kinds['schedule.'.$verb] = ['executor' => 'swamp', 'completion' => ScheduleOperationCompletion::class, 'admission' => ScheduleRuntimeChildAdmission::class, 'execution' => ScheduleOperationExecution::class,
            'runs' => [$run],
            'completion_contract' => ['step' => 'schedule-'.$command, 'transport' => 'agent', 'name' => 'schedule.'.$command, 'result' => []],
            'result_fields' => ['error_code']];
    }

    $kinds['schedule.run'] = ['executor' => 'swamp', 'completion' => ScheduleOperationCompletion::class, 'runs' => [],
        'completion_contract' => ['step' => 'schedule-control', 'transport' => 'agent', 'name' => 'schedule.run', 'result' => []],
        'result_fields' => ['ready', 'error_code']];

    if (OperationKinds::supports('children')) {
        $kinds['schedule.install_instance_definitions'] = [
            'executor' => 'swamp', 'admission' => ScheduleInstanceOperation::class, 'completion' => ScheduleInstanceOperation::class,
            'execution' => ScheduleInstanceOperation::class,
            'runs' => [['transport' => 'gateway', 'name' => 'schedule.instance_runtime_ready', 'resumable' => true, 'input_fields' => ['instance_id'], 'input_values' => []]],
            'children' => [['kind' => 'schedule.create', 'node_from' => 'operation.node_id']],
            'completion_contract' => ['step' => 'runtime-ready', 'transport' => 'gateway', 'name' => 'schedule.instance_runtime_ready', 'result' => ['ready' => true]],
            'result_fields' => ['ready', 'runtime_child_count'],
        ];
    }

    return $kinds;
})();

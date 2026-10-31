<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;
use App\Domain\Processes\ProcessInstanceOperation;
use App\Domain\Processes\ProcessRuntimeChildAdmission;

return (static function (): array {
    if (! OperationKinds::supports('children') || ! OperationKinds::supports('receipts')) {
        return [];
    }
    $kinds = [];
    $kinds['process.update'] = ['executor' => 'swamp', 'result_fields' => ['ready', 'state', 'error_code']];

    $kinds['process.create'] = ['executor' => 'swamp', 'admission' => ProcessRuntimeChildAdmission::class, 'result_fields' => ['ready', 'state', 'error_code']];

    $kinds['process.restart'] = ['executor' => 'swamp', 'admission' => ProcessRuntimeChildAdmission::class, 'result_fields' => ['ready', 'state', 'error_code']];

    $kinds['process.start'] = ['executor' => 'swamp', 'result_fields' => ['ready', 'state', 'error_code']];

    $kinds['process.stop'] = ['executor' => 'swamp', 'result_fields' => ['ready', 'state', 'error_code']];
    $kinds['process.destroy'] = ['executor' => 'swamp', 'admission' => ProcessRuntimeChildAdmission::class, 'result_fields' => ['ready', 'error_code']];

    foreach (['install_instance_definitions' => 'process.create', 'restart_instance' => 'process.restart'] as $verb => $childKind) {
        if ($verb === 'restart_instance' && ! OperationKinds::supports('list_of')) {
            continue;
        }
        $kinds['process.'.$verb] = [
            'executor' => 'swamp', 'admission' => ProcessInstanceOperation::class, 'completion' => ProcessInstanceOperation::class,
            'execution' => ProcessInstanceOperation::class,
            'runs' => [['transport' => 'gateway', 'name' => 'process.instance_runtime_ready', 'resumable' => true, 'input_fields' => ['instance_id'], 'input_values' => []]],
            'children' => [['kind' => $childKind, 'node_from' => 'operation.node_id']],
            'completion_contract' => ['step' => 'runtime-ready', 'transport' => 'gateway', 'name' => 'process.instance_runtime_ready', 'result' => ['ready' => true]],
            'result_fields' => ['ready', 'runtime_child_count', ...($verb === 'restart_instance' ? [['name' => 'process_ids', 'type' => 'list', 'items' => 'integer', 'max' => 4096]] : [])],
        ];
    }

    return $kinds;
})();

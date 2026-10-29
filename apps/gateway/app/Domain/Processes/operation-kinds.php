<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;
use App\Domain\Processes\ProcessInstanceOperation;
use App\Domain\Processes\ProcessOperationCompletion;
use App\Domain\Processes\ProcessOperationExecution;
use App\Domain\Processes\ProcessRuntimeChildAdmission;

return (static function (): array {
    if (! OperationKinds::supports('children') || ! OperationKinds::supports('receipts')) {
        return [];
    }
    $kinds = [];
    $artifact = ['process_id', 'runtime', 'name'];
    foreach (['create', 'update', 'start', 'stop', 'restart', 'destroy'] as $verb) {
        $runs = [];
        if (in_array($verb, ['create', 'update'], true)) {
            $runs[] = ['transport' => 'agent', 'name' => 'process.remove', 'input_fields' => ['process_id'], 'input_values' => ['vite' => null], 'bind' => ['runtime' => 'input.retired_runtime', 'name' => 'input.retired_name']];
            $runs[] = ['transport' => 'agent', 'name' => 'process.stage-unit', 'input_fields' => ['process_id', 'unit', 'content'], 'input_values' => [], 'secrets' => ['content' => 'process.unit_content']];
            $runs[] = ['transport' => 'agent', 'name' => 'process.activate-unit', 'input_fields' => ['process_id', 'unit', 'desired', 'on_demand', 'change'], 'input_values' => []];
            $runs[] = ['transport' => 'agent', 'name' => 'process.converge-container', 'input_fields' => ['process_id', 'container', 'desired', 'spec', 'image', 'command', 'restart', 'working_directory', 'ports', 'mounts', 'environment'], 'input_values' => [], 'secrets' => ['environment' => 'process.environment', 'command' => 'process.command']];
        } elseif ($verb === 'destroy') {
            $runs[] = ['transport' => 'agent', 'name' => 'process.remove', 'input_fields' => [...$artifact, 'vite'], 'input_values' => []];
        } else {
            $runs[] = ['transport' => 'agent', 'name' => 'process.control', 'input_fields' => [...$artifact, 'on_demand'], 'input_values' => ['operation' => $verb]];
        }
        if ($verb !== 'destroy' && $verb !== 'stop') {
            $runs[] = ['transport' => 'agent', 'name' => 'process.check-release', 'input_fields' => ['directory'], 'input_values' => []];
        }
        if (in_array($verb, ['create', 'update', 'start', 'restart'], true)) {
            foreach (['suspend' => ['operation', 'marker'], 'awake' => ['operation', 'marker'], 'environment' => ['operation', 'application', 'path', 'instance_id', 'marker', 'content']] as $variant => $fields) {
                $runs[] = ['transport' => 'agent', 'name' => 'vite.set-traffic', 'input_fields' => [], 'input_values' => [],
                    'bind' => array_combine($fields, array_map(static fn (string $field): string => 'input.vite_plan.'.$variant.'.'.$field, $fields))];
            }
            $fields = ['unit', 'port', 'process_id', 'probe'];
            $runs[] = ['transport' => 'agent', 'name' => 'vite.check-listener', 'input_fields' => [], 'input_values' => [],
                'bind' => array_combine($fields, array_map(static fn (string $field): string => 'input.vite_plan.listener.'.$field, $fields))];
            $runs[] = ['transport' => 'agent', 'name' => 'process.control', 'input_fields' => [...$artifact, 'on_demand'], 'input_values' => ['operation' => 'stop']];
        }
        if ($verb !== 'destroy') {
            $runs[] = ['transport' => 'agent', 'name' => 'process.status', 'input_fields' => $artifact, 'input_values' => []];
        }
        foreach ($runs as &$run) {
            $run['resumable'] = ! ($run['name'] === 'process.control' && $run['input_values']['operation'] === 'restart');
        }
        unset($run);
        $runs[] = ['transport' => 'gateway', 'name' => 'process.runtime_ready', 'resumable' => true, 'input_fields' => ['process_id'], 'input_values' => []];
        $kinds['process.'.$verb] = ['executor' => 'swamp', 'completion' => ProcessOperationCompletion::class, 'execution' => ProcessOperationExecution::class, 'admission' => ProcessRuntimeChildAdmission::class,
            'runs' => $runs, 'children' => in_array($verb, ['create', 'update', 'start', 'restart'], true) ? [['kind' => 'route.caddy_converge', 'node_from' => 'operation.node_id']] : [],
            'completion_contract' => ['step' => 'runtime-ready', 'transport' => 'gateway', 'name' => 'process.runtime_ready', 'result' => ['ready' => true]],
            'result_fields' => ['state', 'staged', 'previous', 'backup_left', 'available', 'owned', 'ready', 'error_code']];
    }

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

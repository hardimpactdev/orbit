<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;
use App\Domain\Processes\ProcessOperationCompletion;

return (static function (): array {
    if (! OperationKinds::supports('children') || ! OperationKinds::supports('list_of') || ! OperationKinds::supports('receipts')) {
        return [];
    }
    $kinds = [];
    $artifact = ['process_id', 'runtime', 'name'];
    foreach (['create', 'update', 'start', 'stop', 'restart', 'destroy'] as $verb) {
        $runs = [];
        if (in_array($verb, ['create', 'update'], true)) {
            $runs[] = ['transport' => 'agent', 'name' => 'process.remove', 'input_fields' => ['process_id'], 'input_values' => ['vite' => null], 'bind' => ['runtime' => 'input.retired_runtime', 'name' => 'input.retired_name']];
            $runs[] = ['transport' => 'agent', 'name' => 'process.stage-unit', 'input_fields' => ['process_id', 'unit', 'content'], 'input_values' => [], 'secrets' => ['content' => 'process.unit_content']];
            foreach (['create', 'replace', 'reload'] as $change) {
                $runs[] = ['transport' => 'agent', 'name' => 'process.activate-unit', 'input_fields' => ['process_id', 'unit', 'desired', 'on_demand'], 'input_values' => ['change' => $change]];
            }
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
        foreach ($runs as &$run) {
            $run['resumable'] = ! ($run['name'] === 'process.control' && ($run['input_values']['operation'] ?? null) === 'restart');
        }
        unset($run);
        $proof = static function (string $variant) use ($verb): array {
            [$step, $name] = match ($verb) {
                'create', 'update' => $variant === 'docker' ? ['process-converge-container', 'process.converge-container'] : ['process-stage-unit', 'process.stage-unit'],
                'destroy' => ['process-remove', 'process.remove'],
                default => ['process-'.$verb, 'process.control'],
            };

            return ['step' => $step, 'transport' => 'agent', 'name' => $name, 'result' => [], ...($variant === 'vite' ? ['children_succeeded' => ['route.caddy_converge']] : [])];
        };
        $kinds['process.'.$verb] = ['executor' => 'swamp', 'completion' => ProcessOperationCompletion::class,
            'runs' => $runs, 'children' => in_array($verb, ['create', 'update', 'start', 'restart'], true) ? [['kind' => 'route.caddy_converge', 'node_from' => 'input.node_id']] : [],
            'completion_contract' => ['completion' => ['by' => 'variant', 'map' => ['systemd' => $proof('systemd'), 'docker' => $proof('docker'), 'vite' => $proof('vite')]]],
            'result_fields' => ['state', 'staged', 'previous', 'backup_left', 'available', 'owned', 'ready', 'error_code']];
    }

    return $kinds;
})();

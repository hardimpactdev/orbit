<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

final class TaskVmSweepContracts
{
    /** @return array<string,array<string,mixed>> */
    public static function kinds(): array
    {
        $kinds = [];
        foreach (['delete', 'delete_upcloud', 'stop_upcloud', 'observe'] as $verb) {
            $fields = ['node_id', 'provider', 'sweep_id'];
            $secrets = [];
            $properties = ['node_id' => ['type' => 'integer', 'minimum' => 1], 'provider' => ['enum' => ['incus', 'upcloud']], 'sweep_id' => ['type' => 'integer', 'minimum' => 1], 'action' => ['const' => $verb === 'observe' ? 'observe' : ($verb === 'stop_upcloud' ? 'stop' : 'delete')]];
            if ($verb !== 'observe') {
                $fields[] = 'deletion';
                $properties['deletion'] = ['type' => 'object'];
            }
            if (in_array($verb, ['delete_upcloud', 'stop_upcloud'], true)) {
                $fields[] = 'token';
                $secrets = ['token' => 'upcloud.api'];
                $properties['token'] = ['type' => 'string'];
            }
            $kinds['task_vm.sweep_'.$verb] = ['executor' => 'swamp', 'completion' => TaskVmEffectCompletion::class,
                'runs' => [['transport' => 'task_vm_sweep', 'name' => 'task_vm.sweep', 'node_field' => 'node_id', 'input_fields' => $fields,
                    'input_values' => ['action' => $verb === 'observe' ? 'observe' : ($verb === 'stop_upcloud' ? 'stop' : 'delete')], 'secrets' => $secrets,
                    'input_schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($properties), 'properties' => $properties]]],
                'completion_contract' => ['step' => 'sweep-'.str_replace('_', '-', $verb), 'transport' => 'task_vm_sweep', 'name' => 'task_vm.sweep', 'result' => ['ready' => true]], 'result_fields' => ['ready', 'error_code']];
        }
        $kinds['task_vm.sweep_host'] = ['executor' => 'swamp', 'completion' => TaskVmEffectCompletion::class, 'runs' => [],
            'children' => array_map(static fn (string $kind): array => ['kind' => $kind, 'node_from' => 'input.node_id'], array_keys($kinds)),
            'completion_contract' => ['children_succeeded' => ['task_vm.sweep_observe']], 'result_fields' => ['ready', 'error_code']];

        return $kinds;
    }
}

<?php

declare(strict_types=1);

use App\Domain\Operations\OperationKinds;
use App\Domain\TaskVms\TaskVmAgentExecution;
use App\Domain\TaskVms\TaskVmChildAdmission;
use App\Domain\TaskVms\TaskVmCommandContracts;
use App\Domain\TaskVms\TaskVmDestroyCancelGuard;
use App\Domain\TaskVms\TaskVmEffectCompletion;
use App\Domain\TaskVms\TaskVmFamilyChildren;
use App\Domain\TaskVms\TaskVmSweepContracts;
use App\Domain\TaskVms\TaskVmUpCloudImageContracts;

return (static function (): array {
    $kinds = [];
    foreach (['create', 'start', 'stop', 'delete'] as $verb) {
        $kinds['task_vm.incus_'.$verb] = [
            'executor' => 'swamp',
            'completion' => TaskVmEffectCompletion::class,
            'execution' => TaskVmAgentExecution::class,
            'runs' => [[
                'transport' => 'agent', 'name' => 'incus.vm', 'resumable' => true,
                'input_fields' => $verb === 'create' || $verb === 'delete' ? ['vm', 'operation'] : ['vm'],
                'input_values' => $verb === 'start' || $verb === 'stop' ? ['operation' => ['kind' => $verb]] : [],
            ]],
            'completion_contract' => [
                'step' => 'incus-'.$verb, 'transport' => 'agent', 'name' => 'incus.vm',
                'result' => ['ready' => true],
            ],
            'result_fields' => ['ready', 'error_code'],
        ];
    }

    $kinds = [...$kinds, ...TaskVmCommandContracts::guestKinds(), ...TaskVmCommandContracts::runtimeKinds(), ...TaskVmCommandContracts::lifecycleKinds(), ...TaskVmCommandContracts::packageCacheKinds(), ...TaskVmCommandContracts::imageKinds(), ...TaskVmUpCloudImageContracts::kinds(), ...TaskVmSweepContracts::kinds()];
    foreach ($kinds as $kind => &$definition) {
        if ($kind === 'task_vm.destroy') {
            if (! OperationKinds::supports('cancel_guard')) {
                unset($kinds[$kind]);

                continue;
            }
            $definition['cancel_guard'] = TaskVmDestroyCancelGuard::class;
        }
        $localKinds = ['task_vm.model_issue', 'task_vm.model_revoke', 'task_vm.runtime_confirm', 'task_vm.image_phase_confirm'];
        if (! OperationKinds::supports('receipts') && ! in_array($kind, $localKinds, true)) {
            unset($kinds[$kind]);

            continue;
        }

        if (isset($definition['runs']) && is_array($definition['runs'])) {
            foreach ($definition['runs'] as &$run) {
                if (is_array($run) && (in_array($run['transport'], ['task_vm_runtime', 'task_vm_upcloud', 'task_vm_package-cache', 'task_vm_lifecycle', 'task_vm_sweep', 'task_vm_upcloud-image'], true) || in_array($kind, ['task_vm.image_promote', 'task_vm.image_prune', 'task_vm.guest_install', 'task_vm.guest_pi_upload', 'task_vm.pi_install', 'task_vm.configure_runtime'], true))) {
                    $run['resumable'] = true;
                }
            }
            unset($run);
        }
        if (isset($definition['children'])) {
            if (! OperationKinds::supports('children')) {
                unset($kinds[$kind]);

                continue;
            }
            $definition['execution'] = TaskVmFamilyChildren::class;
            if (! is_array($definition['children'])) {
                throw new LogicException('Child definitions must be a list.');
            }
            foreach ($definition['children'] as &$child) {
                if (is_array($child) && ($child['node_from'] ?? null) === 'target.node_id') {
                    $child['node_from'] = 'intent.guest_node_id';
                }
            }
            unset($child);
        }
        if (OperationKinds::supports('children')) {
            $definition['admission'] = TaskVmChildAdmission::class;
        }
        if (str_starts_with($kind, 'task_vm.fill_package_cache') && (! OperationKinds::supports('list_of') || ! OperationKinds::supports('children_settled'))) {
            unset($kinds[$kind]);
        }
    }
    unset($definition);

    return $kinds;
})();

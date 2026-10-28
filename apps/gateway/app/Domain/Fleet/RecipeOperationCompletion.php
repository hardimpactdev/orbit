<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;
use App\Support\ValidatedData;

/** Projects only proven recipe application, inventory, Doctor and locked delivery results. */
final readonly class RecipeOperationCompletion implements OperationCompletion
{
    public function __construct(private RecipeMaintenanceAlerts $alerts, private FleetFootprintChild $children) {}

    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        $input = $operation->input;
        $steps = ValidatedData::object($operation->state['steps'] ?? []);
        $node = Node::query()->findOrFail($operation->node_id);
        if ($outcome->status === OperationStatus::Succeeded) {
            $delivery = $this->receipt($operation, 'recipe.verify-delivery');
            if (count($delivery) !== 3
                || ($this->receipt($operation, 'fleet.verify', 'gateway')['passed'] ?? false) !== true
                || ($steps['verify'] ?? null) !== $this->receipt($operation, 'fleet.verify', 'gateway')
                || ($this->receipt($operation, 'recipe.apply')['healthy'] ?? false) !== true
                || ($steps['recipe-apply'] ?? null) !== $this->receipt($operation, 'recipe.apply')
                || ($steps['recipe-delivery'] ?? null) !== $delivery
                || ($delivery['identity'] ?? null) !== $input['identity'] || ($delivery['agent_sha256'] ?? null) !== $input['agent_sha256']
                || ($delivery['cli_version'] ?? null) !== $input['cli_version']) {
                throw new ResourceOperationException('operation.completion_unproven', 'Recipe application and delivery receipts are required.', 409);
            }
            if (($input['has_php'] ?? false) && ! in_array($this->receipt($operation, 'php.install-build')['outcome'] ?? null, ['current', 'applied'], true)) {
                throw new ResourceOperationException('operation.completion_unproven', 'A successful PHP build receipt is required.', 409);
            }
            if (($input['has_php'] ?? false) && ($input['vpn'] ?? false)
                && ($this->receipt($operation, 'php.install-build')['outcome'] ?? null) === 'applied') {
                $this->children->succeeded($operation);
            }
            $settings = $node->settings ?? [];
            $held = ($input['incus_hold'] ?? []) !== [];
            $newer = $this->receipt($operation, 'recipe.apply')['newer_than_recipe'] ?? [];
            $settings['machine_recipe'] = [($held ? 'partial_version' : 'version') => $input['identity'], 'snapshot' => $input['snapshot'], 'converged_at' => now()->toIso8601String(), ...($newer === [] ? [] : ['newer_than_recipe' => $newer])];
            if (! $held) {
                unset($settings['machine_recipe_incus_hold']);
            }
            $node->update(['settings' => $settings]);
        }
        $build = isset($input['build_id']) ? TaskVmImageBuild::query()->find(ValidatedData::integer($input['build_id'])) : null;
        if ($build === null) {
            return;
        }
        if ($outcome->errorCode === 'fleet.window_closed') {
            $build->forceFill(['fleet_convergence' => [...($build->fleet_convergence ?? []), 'status' => 'ready']])->save();

            return;
        }
        $progress = $build->fleet_convergence ?? [];
        if ($outcome->status === OperationStatus::Succeeded) {
            $completed = is_array($progress['nodes'] ?? null) ? $progress['nodes'] : [];
            $completed[$node->id] = ['name' => $node->name, 'operation_id' => $operation->id,
                'changes' => $this->receipt($operation, 'recipe.apply'), 'finished_at' => now()->toIso8601String()];
            $build->forceFill(['fleet_convergence' => [...$progress, 'nodes' => $completed, 'status' => 'ready']])->save();
        } else {
            $summary = 'Machine recipe ['.$build->recipe_identity.'] halted on Node '.$node->id.': '.($outcome->errorCode ?? 'operation.cancelled').'. No automatic rollback or retry.';
            $progress = [...$progress, 'status' => 'halted', 'message' => $summary, 'finished_at' => now()->toIso8601String()];
            $build->forceFill(['fleet_convergence' => [...$progress, 'alert' => $this->alerts->halted($summary, $build->id)]])->save();
        }
    }

    /** @return array<string, mixed> */
    private function receipt(Operation $operation, string $name, string $transport = 'agent'): array
    {
        foreach (ValidatedData::object($operation->state['executor_runs'] ?? []) as $run) {
            if (is_array($run) && ($run['transport'] ?? null) === $transport && ($run['name'] ?? null) === $name && is_array($run['result'] ?? null) && ! isset($run['result']['error_code'])) {
                return ValidatedData::object($run['result']);
            }
        }

        return [];
    }
}

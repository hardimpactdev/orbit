<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Domain\Fleet\RecipeMaintenanceAlerts;
use App\Domain\Shared\ResourceOperationException;
use App\Models\TaskVmImageBuild;

/** Records a terminal plan outcome. Does no Node work. */
final readonly class RecordRecipeFleetStateAction
{
    public function __construct(private RecipeFleetPlanAction $plan, private RecipeMaintenanceAlerts $alerts) {}

    public function execute(int $buildId, string $state): void
    {
        $plan = $this->plan->execute();
        if (($plan['build_id'] ?? null) !== $buildId || ($plan['status'] ?? null) !== $state || ! in_array($state, ['complete', 'interrupted'], true)) {
            throw new ResourceOperationException('fleet.recipe_not_ready', 'The recipe fleet state changed.', 409);
        }
        $record = TaskVmImageBuild::query()->findOrFail($buildId);
        $progress = $record->fleet_convergence ?? ['generation' => $record->recipe_generation, 'nodes' => []];
        if ($state === 'complete') {
            $record->forceFill(['fleet_convergence' => [...$progress, 'status' => 'succeeded', 'finished_at' => now()->toIso8601String()]])->save();

            return;
        }
        $summary = 'Machine recipe ['.$record->recipe_identity.'] halted: interrupted visit. Inspect Doctor before manual recovery. No automatic rollback or retry.';
        $record->forceFill(['fleet_convergence' => [...$progress, 'status' => 'halted', 'message' => $summary,
            'finished_at' => now()->toIso8601String(), 'alert' => $this->alerts->halted($summary, $record->id)]])->save();
    }
}

<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Fleet\FleetVisitPrerequisites;
use App\Domain\Fleet\FleetWindow;
use App\Domain\Fleet\RecipeMaintenanceAlerts;
use App\Domain\Releases\ReleaseIncident;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class AdmitNodeRecipeAction
{
    public function __construct(private PlanNodeRecipeAction $plan, private StartOperationAction $start, private FleetWindow $window, private RecipeMaintenanceAlerts $alerts, private FleetVisitPrerequisites $prerequisites) {}

    public function execute(Node $node, MachineRecipe $recipe, bool $automatic = false, ?int $buildId = null, ?string $triggeredBy = null, ?string $requestId = null): Operation
    {
        $this->prerequisites->assertAvailable();
        $node->load('roles');
        if ($automatic && ! $this->window->allows($node)) {
            throw new ResourceOperationException('fleet.window_closed', 'Automatic fleet changes wait for 03:00–06:00 Europe/Amsterdam on this Node.', 409);
        }
        if ($buildId !== null) {
            $fleet = app(RecipeFleetPlanAction::class)->execute();
            if (! $automatic || ($fleet['status'] ?? null) !== 'ready' || ($fleet['node_id'] ?? null) !== $node->id
                || ($fleet['build_id'] ?? null) !== $buildId || ($fleet['recipe'] ?? null) !== $recipe->identity) {
                throw new ResourceOperationException('fleet.recipe_not_ready', 'The build generation, order or fleet gate changed. Nothing was admitted.', 409);
            }
        }
        try {
            $input = [...$this->plan->execute($node, $recipe, $automatic), 'build_id' => $buildId];
        } catch (\Throwable $error) {
            if ($buildId !== null && $error instanceof ResourceOperationException && $error->errorCode === 'node.recipe_docker_provider') {
                $build = TaskVmImageBuild::query()->findOrFail($buildId);
                $progress = $build->fleet_convergence ?? ['generation' => $build->recipe_generation, 'nodes' => []];
                $completed = is_array($progress['nodes'] ?? null) ? $progress['nodes'] : [];
                $completed[$node->id] = ['name' => $node->name, 'status' => 'skipped', 'reason' => 'docker-provider:docker-ce', 'finished_at' => now()->toIso8601String()];
                $build->forceFill(['fleet_convergence' => [...$progress, 'nodes' => $completed, 'status' => 'ready']])->save();
            }
            if ($buildId !== null && ! ($error instanceof ResourceOperationException && $error->errorCode === 'node.recipe_docker_provider')) {
                $build = TaskVmImageBuild::query()->findOrFail($buildId);
                $code = $error instanceof ResourceOperationException ? $error->errorCode : 'fleet.recipe_plan_failed';
                $summary = 'Machine recipe ['.$recipe->identity.'] halted while planning Node '.$node->id.': '.$code.'. No automatic rollback or retry.';
                $progress = [...($build->fleet_convergence ?? []), 'status' => 'halted', 'message' => $summary, 'finished_at' => now()->toIso8601String()];
                $build->forceFill(['fleet_convergence' => [...$progress, 'alert' => $this->alerts->halted($summary, $build->id)]])->save();
            }
            throw $error;
        }

        return DB::transaction(function () use ($node, $input, $triggeredBy, $requestId, $buildId): Operation {
            $operation = $this->start->execute('fleet.recipe', 'node', $node->id, $node->id, $input, $triggeredBy, $requestId);
            if (! $operation->wasRecentlyCreated) {
                return $operation;
            }
            if ($input['automatic']) {
                $operation->forceFill(['run_constraints' => FleetWindow::Constraints])->save();
            }
            $settings = $node->settings ?? [];
            $pins = ValidatedData::stringMap($input['incus_hold']);
            if ($pins !== []) {
                $fingerprint = hash('sha256', json_encode($pins, JSON_THROW_ON_ERROR));
                if ((ValidatedData::object($settings['machine_recipe_incus_hold'] ?? [])['fingerprint'] ?? null) !== $fingerprint) {
                    $settings['machine_recipe_incus_hold'] = ['fingerprint' => $fingerprint, 'pins' => $pins, 'held_at' => now()->toIso8601String()];
                    $this->alerts->halted("Node [{$node->id}: {$node->name}] holds Incus ".implode(', ', $pins).'. Drain all task VMs, builders and CI runners, then run explicit node:converge-recipe. Other recipe changes continue automatically.',
                        target: 'machine-recipe-incus-'.$node->id.'-'.substr($fingerprint, 0, 24), incident: new ReleaseIncident('recipe-incus:'.$node->id, $fingerprint, 'upgrade_held'));
                }
            }
            $node->update(['settings' => $settings]);
            if ($buildId !== null) {
                $build = TaskVmImageBuild::query()->findOrFail($buildId);
                $progress = $build->fleet_convergence ?? ['generation' => $build->recipe_generation, 'nodes' => []];
                $build->forceFill(['fleet_convergence' => [...$progress, 'generation' => $build->recipe_generation, 'status' => 'visiting', 'node_id' => $node->id, 'operation_id' => $operation->id]])->save();
            }

            return $operation;
        });
    }
}

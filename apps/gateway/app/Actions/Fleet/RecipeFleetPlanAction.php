<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Domain\Fleet\FleetServingRelease;
use App\Domain\Fleet\FleetVisitPrerequisites;
use App\Domain\Fleet\FleetWindow;
use App\Domain\Fleet\NodeRecipeVerification;
use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/** Reads the current generation's build gate, halt state and next ordered recipe target. */
final readonly class RecipeFleetPlanAction
{
    public function __construct(private RecipeImageBuilds $builds, private TaskVmSettings $settings, private ManagedNodeEligibility $eligibility, private GatewayServingHost $gateway, private FleetServingRelease $serving, private FleetWindow $window, private FleetVisitPrerequisites $prerequisites) {}

    /** @return array<string, mixed> */
    public function execute(): array
    {
        try {
            $this->prerequisites->assertAvailable();
        } catch (ResourceOperationException $error) {
            return ['status' => 'unavailable', 'error_code' => $error->errorCode];
        }
        if ($this->serving->read()['state'] !== FleetServingRelease::Adopted || $this->serving->supersedes() || $this->settings->hosts === []) {
            return ['status' => 'disabled'];
        }
        $recipe = $this->builds->currentRecipe();
        $desired = DB::table('task_vm_image_recipe')->where('id', 1)->first();
        if ($desired === null || $desired->identity !== $recipe->identity) {
            return ['status' => 'waiting'];
        }
        $generation = (int) $desired->generation;
        $builds = TaskVmImageBuild::query()->where('recipe_identity', $recipe->identity)->where('recipe_generation', $generation)->orderBy('id')->get();
        $record = $builds->first();
        if ($record === null) {
            return ['status' => 'waiting'];
        }
        $progress = $record->fleet_convergence ?? [];
        if (($progress['generation'] ?? null) !== $generation) {
            $progress = ['generation' => $generation, 'status' => 'ready', 'nodes' => []];
        }
        $base = ['build_id' => $record->id, 'recipe' => $recipe->identity, 'generation' => $generation];
        if (in_array($progress['status'], ['halted', 'succeeded'], true)) {
            return [...$base, 'status' => $progress['status']];
        }
        if ($progress['status'] === 'visiting') {
            $operation = isset($progress['operation_id']) ? Operation::query()->find(ValidatedData::integer($progress['operation_id'])) : null;

            return [...$base, 'status' => $operation !== null && ! $operation->status->terminal() ? 'busy' : 'interrupted'];
        }
        foreach ($this->settings->hosts as $host) {
            if (! $builds->contains(static fn (TaskVmImageBuild $build): bool => $build->host_node_id === $host->nodeId
                && $build->state === TaskVmImageBuildState::Succeeded && $build->promoted_at !== null && isset($build->images['base']))) {
                return [...$base, 'status' => 'waiting'];
            }
        }
        $nodes = Node::query()->with('roles')->where('status', LifecycleStatus::Active)->where('platform', 'linux')->orderBy('id')->get()
            ->filter(fn (Node $node): bool => $this->eligibility->allows($node) && $node->roles->isNotEmpty() && TaskVm::forNode($node) === null)
            ->sortBy(fn (Node $node): array => [$this->tier($node), $node->id]);
        foreach ($nodes as $node) {
            $visited = is_array($progress['nodes'] ?? null) ? $progress['nodes'] : [];
            $previous = is_array($visited[$node->id] ?? null) ? $visited[$node->id] : [];
            if (($previous['reason'] ?? null) === 'docker-provider:docker-ce') {
                continue;
            }
            $verified = ValidatedData::object($node->settings['machine_recipe'] ?? []);
            if (NodeRecipeVerification::verified($node)
                && (($verified['version'] ?? null) === $recipe->identity || ($verified['partial_version'] ?? null) === $recipe->identity)) {
                continue;
            }

            return [...$base, 'status' => $this->window->allows($node) ? 'ready' : 'window_closed', 'node_id' => $node->id];
        }

        return [...$base, 'status' => 'complete'];
    }

    private function tier(Node $node): int
    {
        if ($this->gateway->is($node)) {
            return 5;
        }
        $tier = 1;
        foreach ($node->roles as $role) {
            $tier = max($tier, match ($role->role->value) {
                'gateway' => 5, 'vpn' => 4, 'app-prod' => 3, 'app-dev' => 1, default => 2,
            });
        }

        return $tier;
    }
}

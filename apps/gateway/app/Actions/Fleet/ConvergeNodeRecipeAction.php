<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Data\Fleet\NodeRecipePlan;
use App\Domain\Fleet\FleetNodeVerification;
use App\Domain\Fleet\RecipeMaintenanceAlerts;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Releases\ReleaseIncident;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\Tools\DebianVersionNormalizer;
use App\Domain\Tools\ToolInspector;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerScopeLock;
use App\Domain\Tools\VersionConstraint;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\NodeRecipeProgram;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\MachineSoftware\ToolPin;
use App\Infrastructure\Nodes\NodeAgentFootprint;
use App\Infrastructure\Nodes\NodeUpdateLock;
use App\Infrastructure\Nodes\Roles\NodeRoleConvergeLock;
use App\Models\Node;
use App\Models\TaskVm;
use App\Models\Tool;
use Closure;
use Throwable;

/** One Node only. The caller must stop advancement when this operation throws. */
final readonly class ConvergeNodeRecipeAction
{
    public function __construct(
        private ManagedNodeEligibility $eligibility,
        private NodeRoleConvergeLock $lock,
        private NodeUpdateLock $updateLock,
        private ToolManagerScopeLock $toolLocks,
        private NodeRecipeProgram $program,
        private ToolInspector $inspector,
        private VersionConstraint $constraints,
        private DebianVersionNormalizer $versions,
        private FleetNodeVerification $verifier,
        private RecipeMaintenanceAlerts $alerts,
    ) {}

    /** @return array{plan: NodeRecipePlan, applied: bool, changes: array<string, mixed>} */
    public function execute(Node $node, MachineRecipe $recipe, bool $dryRun = false, bool $automatic = false): array
    {
        $this->eligible($node);
        if ($dryRun) {
            return ['plan' => $this->plan($node, $recipe), 'applied' => false, 'changes' => []];
        }

        return $this->lock->run($node, fn (): array => $this->withToolLocks($node, function () use ($node, $recipe, $automatic): array {
            $node->refresh();
            $this->eligible($node);
            $plan = $this->plan($node, $recipe);

            $tools = $this->program->tools($node, $recipe);
            $held = $automatic && $plan->changesIncus();
            if ($held) {
                $this->holdIncus($node, array_intersect_key($tools, array_flip(RecipeLoader::IncusPackages)));
            }
            // Automatic visits never send Incus pins, including when inspection found no change.
            if ($automatic) {
                $tools = array_diff_key($tools, array_flip(RecipeLoader::IncusPackages));
            }
            if (! $automatic && $plan->changesIncus()) {
                $vms = TaskVm::query()->where('host_node_id', $node->id)->where('state', '!=', TaskVmState::Destroyed)->orderBy('id')->get();
                if ($vms->isNotEmpty()) {
                    throw new ResourceOperationException('node.recipe_incus_busy', 'Incus changes require draining live task VMs: '.implode(', ', $vms->map(static fn (TaskVm $vm): string => $vm->name.' ('.$vm->state->value.')')->all()).'. Nothing was changed.', 409);
                }
            }

            $this->program->incusPreflight($node, $recipe, $tools);

            return $this->updateLock->run($node, function () use ($node, $recipe, $plan, $tools, $held): array {
                // Clear the success marker before a run that may leave the Node partly changed.
                $settings = $node->settings ?? [];
                unset($settings['machine_recipe']);
                $node->update(['settings' => $settings]);
                $changes = $this->program->apply($node, $recipe, $tools);
                $this->verifyTools($node);
                $report = $this->verifier->verify($node, [], NodeAgentFootprint::Version);
                if (! $report['passed']) {
                    throw new ResourceOperationException('node.recipe_doctor_failed', 'Recipe convergence stopped at Doctor. The Node may be partly changed. No automatic rollback was attempted. Run Doctor for this Node before continuing.', 502);
                }
                $settings = $node->refresh()->settings ?? [];
                $settings['machine_recipe'] = [($held ? 'partial_version' : 'version') => $recipe->identity, 'snapshot' => $recipe->snapshot, 'converged_at' => now()->toIso8601String(), ...(($changes['newer_than_recipe'] ?? []) === [] ? [] : ['newer_than_recipe' => $changes['newer_than_recipe']])];
                if (! $held) {
                    unset($settings['machine_recipe_incus_hold']);
                }
                $node->update(['settings' => $settings]);

                return ['plan' => $plan, 'applied' => true, 'changes' => $changes];
            });
        }), errorCode: 'node.recipe_failed');
    }

    /** @param array<string, ToolPin> $pins */
    private function holdIncus(Node $node, array $pins): void
    {
        $versions = array_map(static fn (ToolPin $pin): string => $pin->version, $pins);
        $fingerprint = hash('sha256', json_encode($versions, JSON_THROW_ON_ERROR));
        $settings = $node->settings ?? [];
        $hold = $settings['machine_recipe_incus_hold'] ?? null;
        if (is_array($hold) && ($hold['fingerprint'] ?? null) === $fingerprint) {
            return;
        }
        $settings['machine_recipe_incus_hold'] = ['fingerprint' => $fingerprint, 'pins' => $versions, 'held_at' => now()->toIso8601String()];
        $node->update(['settings' => $settings]);
        $this->alerts->halted("Node [{$node->id}: {$node->name}] holds Incus ".implode(', ', $versions).'. Drain all task VMs, builders and CI runners, then run explicit node:converge-recipe. Other recipe changes continue automatically.', target: 'machine-recipe-incus-'.$node->id.'-'.substr($fingerprint, 0, 24), incident: new ReleaseIncident('recipe-incus:'.$node->id, $fingerprint, 'upgrade_held'));
    }

    /**
     * @param  Closure(): array{plan: NodeRecipePlan, applied: bool, changes: array<string, mixed>}  $operation
     * @return array{plan: NodeRecipePlan, applied: bool, changes: array<string, mixed>}
     */
    private function withToolLocks(Node $node, Closure $operation, int $index = 0): array
    {
        $managers = [ToolManagerName::Apt, ToolManagerName::Composer, ToolManagerName::Vp, ToolManagerName::Brew];
        if (! isset($managers[$index])) {
            return $operation();
        }

        return $this->toolLocks->run($node->id, $managers[$index], fn (): array => $this->withToolLocks($node, $operation, $index + 1));
    }

    private function eligible(Node $node): void
    {
        if (! $this->eligibility->allows($node)) {
            throw new ResourceOperationException('node.recipe_unsupported', 'Recipe convergence requires an active managed Linux Node.', 422);
        }
    }

    private function plan(Node $node, MachineRecipe $recipe): NodeRecipePlan
    {
        $pins = $this->program->tools($node, $recipe);
        $this->checkConstraints($node, $pins);
        // Another PHP source or a PHP package outside the recipe refuses the plan, also in a dry run.
        $this->program->phpPreflight($node, $recipe, $pins);
        $inspection = $this->program->inspect($node, $recipe, $pins);

        return new NodeRecipePlan($node->id, $recipe->identity, $recipe->snapshot, array_values($node->roles->map(fn ($role): string => $role->role->value)->all()), $inspection['tools'], $inspection['actions']);
    }

    /** @param array<string, ToolPin> $pins */
    private function checkConstraints(Node $node, array $pins): void
    {
        foreach ($this->tools($node) as $tool) {
            if ($tool->manager->name !== 'apt' || ! isset($pins[$tool->package]) || $tool->version_constraint === null) {
                continue;
            }
            $version = $this->versions->normalize($pins[$tool->package]->version);
            if ($version === null || ! $this->constraints->isValid($tool->version_constraint) || ! $this->constraints->allows($version, $tool->version_constraint)) {
                throw new ResourceOperationException('node.recipe_tool_conflict', "Recipe pin for [{$tool->package}] conflicts with managed Tool [{$tool->id}]. Nothing was changed.", 409);
            }
        }
    }

    private function verifyTools(Node $node): void
    {
        foreach ($this->tools($node) as $tool) {
            try {
                $observed = $this->inspector->inspect($tool);
            } catch (Throwable $exception) {
                throw new ResourceOperationException('node.recipe_inventory_failed', "Recipe convergence could not verify managed Tool [{$tool->id}]. No automatic rollback was attempted.", 502, $exception);
            }
            if (! $observed->installed || ($tool->version_constraint !== null && ($observed->normalizedVersion === null || ! $this->constraints->isValid($tool->version_constraint) || ! $this->constraints->allows($observed->normalizedVersion, $tool->version_constraint)))) {
                throw new ResourceOperationException('node.recipe_inventory_failed', "Recipe convergence stopped at managed Tool [{$tool->id}] verification. No automatic rollback was attempted.", 502);
            }
        }
    }

    /** @return list<Tool> */
    private function tools(Node $node): array
    {
        return array_values(Tool::query()->with(['node', 'manager'])->where('node_id', $node->id)->orderBy('id')->get()->all());
    }
}

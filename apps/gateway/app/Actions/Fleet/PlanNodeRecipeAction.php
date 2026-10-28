<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Data\Fleet\NodeRecipePlan;
use App\Domain\Fleet\DesiredFleetState;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\Tools\DebianVersionNormalizer;
use App\Domain\Tools\VersionConstraint;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\NodeRecipeProgram;
use App\Infrastructure\MachineSoftware\PhpBuildInstaller;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\MachineSoftware\ToolPin;
use App\Models\Node;
use App\Models\TaskVm;
use App\Models\Tool;

/** Reads and validates recipe intent. All writes on the Node run in Swamp. */
final readonly class PlanNodeRecipeAction
{
    public function __construct(
        private ManagedNodeEligibility $eligibility,
        private DesiredFleetState $desired,
        private NodeRecipeProgram $program,
        private VersionConstraint $constraints,
        private DebianVersionNormalizer $versions,
        private AgentDelivery $delivery,
    ) {}

    /** @return array<string, mixed> */
    public function execute(Node $node, MachineRecipe $recipe, bool $automatic = false): array
    {
        $plan = $this->read($node, $recipe);
        $tools = $this->program->tools($node, $recipe);
        $cli = $this->desired->current()->cli;
        if (! $cli->isAvailable() || $cli->version === null) {
            throw new ResourceOperationException('node.recipe_delivery_unavailable', 'Recipe convergence requires the requested CLI release to be available. Nothing was applied.', 409);
        }
        if (! $automatic && $plan->changesIncus()
            && TaskVm::query()->where('host_node_id', $node->id)->where('state', '!=', TaskVmState::Destroyed)->exists()) {
            throw new ResourceOperationException('node.recipe_incus_busy', 'Incus changes require draining live task VMs. Nothing was changed.', 409);
        }
        $incus = array_intersect_key($tools, array_flip(RecipeLoader::IncusPackages));
        if ($automatic) {
            $tools = array_diff_key($tools, $incus);
        }
        $versions = NodeRecipeProgram::phpVersions($recipe, $tools);
        $profiles = array_values($node->roles->map(static fn ($role): string => $role->role->value)->all());
        $php = $versions === [] ? [] : PhpBuildInstaller::input($recipe, $versions,
            array_intersect($profiles, $recipe->phpPolicy->pcovProfiles) !== [], 'refuse', PhpBuildInstaller::fpmForProfiles($profiles),
            array_intersect($profiles, $recipe->phpPolicy->opentelemetryProfiles) !== []);
        $phpNames = [];
        foreach ($versions as $version) {
            $phpNames = [...$phpNames, ...$recipe->php($version)->packages];
        }
        $payload = NodeRecipeProgram::payload($node, $recipe, array_diff_key($tools, array_flip($phpNames)));

        return [...$payload, ...$php, 'identity' => $recipe->identity, 'cli_version' => $cli->version,
            'agent_sha256' => $recipe->agentDigest((string) $node->architecture), 'automatic' => $automatic,
            'plan' => get_object_vars($plan), 'has_php' => $versions !== [],
            'incus_hold' => $automatic && $plan->changesIncus() ? array_map(static fn (ToolPin $pin): string => $pin->version, $incus) : [],
            'daytime' => $node->roles->isNotEmpty() && $node->roles->every(static fn ($role): bool => $role->role->value === 'app-dev'),
            'node_id' => $node->id, 'vpn' => in_array('vpn', $profiles, true)];
    }

    public function read(Node $node, MachineRecipe $recipe): NodeRecipePlan
    {
        if (! $this->eligibility->allows($node)) {
            throw new ResourceOperationException('node.recipe_unsupported', 'Recipe convergence requires an active managed Linux Node.', 422);
        }
        if ($recipe->agentDigest((string) $node->architecture) !== $this->delivery->pin($node)) {
            throw new ResourceOperationException('node.recipe_delivery_unavailable', 'The recipe agent pin differs from the serving delivery. Nothing was applied.', 409);
        }

        return $this->plan($node, $recipe);
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

    /** @return list<Tool> */
    private function tools(Node $node): array
    {
        return array_values(Tool::query()->with(['node', 'manager'])->where('node_id', $node->id)->orderBy('id')->get()->all());
    }
}

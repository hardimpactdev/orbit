<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Data\Fleet\NodeRecipePlan;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\DebianVersionNormalizer;
use App\Domain\Tools\VersionConstraint;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\NodeRecipeProgram;
use App\Infrastructure\MachineSoftware\ToolPin;
use App\Models\Node;
use App\Models\Tool;

/** Reads and validates recipe intent. All writes on the Node run in Swamp. */
final readonly class PlanNodeRecipeAction
{
    public function __construct(
        private ManagedNodeEligibility $eligibility,
        private NodeRecipeProgram $program,
        private VersionConstraint $constraints,
        private DebianVersionNormalizer $versions,
        private AgentDelivery $delivery,
    ) {}

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
        $tools = [];
        foreach ($pins as $name => $pin) {
            $tools[$name] = ['source' => $pin->source, 'version' => $pin->version,
                'installed' => null, 'changed' => false, 'status' => 'unobserved'];
        }

        return new NodeRecipePlan($node->id, $recipe->identity, $recipe->snapshot,
            array_values($node->roles->map(fn ($role): string => $role->role->value)->all()), $tools);

    }

    /** @param array<string, ToolPin> $pins */
    public function checkConstraints(Node $node, array $pins): void
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

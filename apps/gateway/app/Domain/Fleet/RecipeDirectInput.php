<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Actions\Fleet\PlanNodeRecipeAction;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\NodeConvergeInput;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\NodeRecipeProgram;
use App\Infrastructure\MachineSoftware\PhpBuildInstaller;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\MachineSoftware\ToolPin;
use App\Models\Node;

/** Desired records only. Native recipe checkpoints own every host inspection and effect. */
final readonly class RecipeDirectInput
{
    public function __construct(private ManagedNodeEligibility $eligibility, private DesiredFleetState $desired,
        private NodeRecipeProgram $program, private PlanNodeRecipeAction $plan, private AgentDelivery $delivery,
        private NodeConvergeInput $footprint, private CliConvergePlan $cli) {}

    /** @return array<string,mixed> */
    public function capture(Node $node, MachineRecipe $recipe, bool $automatic = false): array
    {
        if (! $this->eligibility->allows($node)) {
            throw new ResourceOperationException('node.recipe_unsupported', 'Recipe convergence requires an active managed Linux Node.', 422);
        }
        if ($recipe->agentDigest((string) $node->architecture) !== $this->delivery->pin($node)) {
            throw new ResourceOperationException('node.recipe_delivery_unavailable', 'The recipe agent pin differs from the serving delivery.', 409);
        }
        $tools = $this->program->tools($node, $recipe);
        $this->plan->checkConstraints($node, $tools);
        $cli = $this->desired->current()->cli;
        if (! $cli->isAvailable() || $cli->version === null) {
            throw new ResourceOperationException('node.recipe_delivery_unavailable', 'Recipe convergence requires an available CLI release.', 409);
        }
        $incus = array_intersect_key($tools, array_flip(RecipeLoader::IncusPackages));
        if ($automatic) {
            $tools = array_diff_key($tools, $incus);
        }
        $versions = NodeRecipeProgram::phpVersions($recipe, $tools);
        $profiles = array_values($node->roles->map(static fn ($role): string => $role->role->value)->all());
        $php = $versions === [] ? null : PhpBuildInstaller::input($recipe, $versions,
            array_intersect($profiles, $recipe->phpPolicy->pcovProfiles) !== [], 'refuse', PhpBuildInstaller::fpmForProfiles($profiles),
            array_intersect($profiles, $recipe->phpPolicy->opentelemetryProfiles) !== []);
        $phpNames = [];
        foreach ($versions as $version) {
            $phpNames = [...$phpNames, ...$recipe->php($version)->packages];
        }
        $footprint = $this->footprint->capture($node, true, false);
        $secret = $footprint['secret_hash'];
        unset($footprint['secret_hash']);

        return ['node_id' => $node->id, 'identity' => $recipe->identity, 'snapshot' => $recipe->snapshot,
            'agent_sha256' => $recipe->agentDigest((string) $node->architecture), 'cli_version' => $cli->version,
            'automatic' => $automatic, 'daytime' => $node->roles->isNotEmpty() && $node->roles->every(static fn ($role): bool => $role->role->value === 'app-dev'),
            'recipe' => NodeRecipeProgram::payload($node, $recipe, array_diff_key($tools, array_flip($phpNames))), 'php' => $php,
            'incus_hold' => $automatic ? array_map(static fn (ToolPin $pin): string => $pin->version, $incus) : [],
            'footprint' => $footprint, 'cli' => $this->cli->input($node),
            'secret_hash' => $secret];
    }
}

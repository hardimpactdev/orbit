<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use RuntimeException;

/** Desired recipe pins and immutable native inputs. No transport or host actions. */
final readonly class NodeRecipeProgram
{
    /** The tools `recipe.install-javascript` installs. */
    public const array JavascriptTools = ['bun', 'node', 'pnpm', 'vp', 'vp-cli'];

    /** The recipe architecture of a Node; another architecture is refused. */
    public static function architecture(Node $node): string
    {
        return MachineRecipe::architecture($node->architecture)
            ?? throw new ResourceOperationException('node.recipe_architecture', 'The Node architecture is not supported by the recipe.', 422);
    }

    /** @return array<string, ToolPin> */
    public function tools(Node $node, MachineRecipe $recipe): array
    {
        self::architecture($node);
        $node->load('roles');
        foreach ($node->roles as $role) {
            if ($role->status !== LifecycleStatus::Active) {
                throw new ResourceOperationException('node.recipe_role_inactive', 'Every assigned role must be active before recipe convergence.', 409);
            }
        }

        return $this->toolsForRoles($node, $recipe, array_values($node->roles->map(static fn ($role): RoleName => $role->role)->all()));
    }

    /** Public selection for roles already admitted by their owning parent.
     *
     * @param  list<RoleName>  $roles
     * @return array<string,ToolPin>
     */
    public function toolsForRoles(Node $node, MachineRecipe $recipe, array $roles): array
    {
        $architecture = self::architecture($node);
        $names = $roles === [] ? $recipe->profile('operator', $architecture) : [];
        foreach ($roles as $role) {
            $names = [...$names, ...$recipe->profile($role->value, $architecture)];
        }
        if (is_array($node->settings['task_vms'] ?? null)) {
            $names = [...$names, ...RecipeLoader::IncusPackages];
        }
        $tools = [];
        foreach (array_unique($names) as $name) {
            $pin = $recipe->tools[$name] ?? throw new ResourceOperationException('node.recipe_pin_missing', "The recipe has no pin for role prerequisite [{$name}]. Nothing was changed.", 422);
            if ($pin->source !== 'ubuntu' && ! isset($pin->artifacts[$architecture])) {
                throw new ResourceOperationException('node.recipe_artifact_missing', "The recipe has no {$architecture} artifact for [{$name}].", 422);
            }
            $tools[$name] = $pin->forArchitecture($architecture);
        }
        ksort($tools);

        return $tools;
    }

    /**
     * The PHP versions whose whole set the tools hold.
     *
     * @param  array<string, ToolPin>  $tools
     * @return list<string>
     */
    public static function phpVersions(MachineRecipe $recipe, array $tools): array
    {
        return array_map(strval(...), array_keys($recipe->phpExtensions(array_keys($tools))));
    }

    /**
     * The typed recipe input for the selected tools. `recipe.inspect`, `recipe.apply`, and `recipe.install-javascript`
     * take the same shape.
     *
     * @param  array<string, ToolPin>  $tools
     * @return array<string, mixed>
     */
    public static function payload(Node $node, MachineRecipe $recipe, array $tools): array
    {
        $data = json_decode($recipe->json, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($data) || ! is_array($data['tools'] ?? null)) {
            throw new RuntimeException('The recipe document is invalid.');
        }
        foreach ($tools as $name => $pin) {
            if (! is_array($data['tools'][$name] ?? null)) {
                throw new RuntimeException('A selected recipe tool is invalid.');
            }
            $data['tools'][$name]['version'] = $pin->version;
        }

        return [
            'user' => $node->user,
            'snapshot' => $recipe->snapshot,
            'git_packages' => $recipe->gitPackages, 'artifact_mirror' => $recipe->artifactMirror,
            'architecture' => self::architecture($node),
            'tools' => array_intersect_key($data['tools'], $tools),
            'vp_lock' => isset($tools['vp']) ? VitePlusRuntimeLock::read($tools['vp']) : null,
        ];
    }

    /**
     * The `recipe.install-javascript` input for the JavaScript runtimes a role's recipe profile pins, or null when it
     * pins none. Role convergence and image programs install them with the same verified archives and launchers as
     * recipe convergence.
     *
     * @return array<string, mixed>|null
     */
    public static function javascript(Node $node, RoleName $role): ?array
    {
        $recipe = new RecipeLoader()->load();
        $architecture = self::architecture($node);
        $tools = [];
        foreach (array_intersect($recipe->profile($role->value, $architecture), self::JavascriptTools) as $name) {
            $tools[$name] = $recipe->tools[$name]->forArchitecture($architecture);
        }

        return $tools === [] ? null : self::payload($node, $recipe, $tools);
    }
}

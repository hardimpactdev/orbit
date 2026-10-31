<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\RoleName;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Models\Node;
use InvalidArgumentException;
use stdClass;

/** Public metadata for native bootstrap and image consumers; contains no executable programs. */
final readonly class NodeEnrollmentRecipe
{
    private const array Roles = [RoleName::Gateway, RoleName::Vpn, RoleName::Router, RoleName::Ingress,
        RoleName::AppDev, RoleName::AppProd, RoleName::Database, RoleName::WebSocket];

    public function __construct(private NodeBootstrapCommandFactory $bootstrap, private RecipeLoader $recipes) {}

    /** @param list<RoleName> $roles
     * @return array{schema_version:int,node:array{platform:string,architecture:string,user:string,home:string,roles:list<string>},bootstrap:array<string,mixed>,recipe:stdClass}
     */
    public function metadata(array $roles, string $architecture = 'x86_64'): array
    {
        if ($architecture !== 'x86_64') {
            throw new InvalidArgumentException('Linux enrollment supports x86_64 only.');
        }
        $names = [];
        foreach ($roles as $role) {
            if (! in_array($role, self::Roles, true)) {
                throw new InvalidArgumentException("Role [{$role->value}] has no image recipe without Gateway records.");
            }
            if (in_array($role->value, $names, true)) {
                throw new InvalidArgumentException("Role [{$role->value}] is repeated.");
            }
            $names[] = $role->value;
        }
        $node = new Node(['name' => 'node-recipe', 'platform' => 'linux', 'user' => 'orbit', 'architecture' => $architecture]);

        $recipe = json_decode($this->recipes->load()->json, false, flags: JSON_THROW_ON_ERROR);
        if (! $recipe instanceof stdClass) {
            throw new \LogicException('The stored machine recipe is not an object.');
        }

        return ['schema_version' => 1,
            'node' => ['platform' => 'linux', 'architecture' => $architecture, 'user' => 'orbit', 'home' => '/home/orbit', 'roles' => $names],
            'bootstrap' => $this->bootstrap->input($node, 'orbit', rolelessOperator: $roles === []),
            'recipe' => $recipe];
    }
}

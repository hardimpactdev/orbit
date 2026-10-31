<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\MachineSoftware\PhpBuildInstaller;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Models\Node;
use App\Support\ValidatedData;

/** Pure package selection. Swamp owns the installer and package recovery. */
final readonly class NodePhpPackagesInput
{
    /** @param array<array-key,mixed> $versions
     * @return array<string,mixed>
     */
    public function forNode(Node $node, array $versions): array
    {
        if (! array_is_list($versions) || $versions === [] || count($versions) > 8
            || array_any($versions, static fn ($version): bool => ! is_string($version) || preg_match('/\A[0-9]+\.[0-9]+\z/D', $version) !== 1)
            || count(array_unique($versions, SORT_REGULAR)) !== count($versions)) {
            throw new ResourceOperationException('node.php_packages_invalid', 'Select one to eight distinct PHP versions.', 422);
        }
        $versions = ValidatedData::stringList($versions);
        $recipe = new RecipeLoader()->load();
        foreach ($versions as $version) {
            if (! isset($recipe->php[$version])) {
                throw new ResourceOperationException('app-prod.php_package_source_unavailable', 'The selected PHP build is absent from the machine recipe.', 422);
            }
        }
        $roles = $node->roles->map(static fn ($role): string => $role->role->value)->all();

        return PhpBuildInstaller::input($recipe, $versions, array_intersect($roles, $recipe->phpPolicy->pcovProfiles) !== [],
            extras: 'keep', fpm: 'none', opentelemetry: array_intersect($roles, $recipe->phpPolicy->opentelemetryProfiles) !== [],
            release: ['id' => 'ubuntu', 'codenames' => array_map(static fn (UbuntuRelease $release): string => $release->value, UbuntuRelease::forRole(RoleName::AppProd))]);
    }
}

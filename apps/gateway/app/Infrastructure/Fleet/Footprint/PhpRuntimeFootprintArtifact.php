<?php

declare(strict_types=1);

namespace App\Infrastructure\Fleet\Footprint;

use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Nodes\PhpFpmRuntimeIniRenderer;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Models\Node;
use App\Models\NodeRole;

final readonly class PhpRuntimeFootprintArtifact implements NodeFootprintArtifact
{
    public function __construct(private RemotePhpPackageManager $php, private RecipeLoader $recipes) {}

    public function name(): string
    {
        return 'php-runtime';
    }

    public function applies(Node $node): bool
    {
        return $node->platform === 'linux' && $node->roles->contains(
            static fn (NodeRole $role): bool => in_array($role->role, [RoleName::AppDev, RoleName::AppProd], true)
                && $role->status === LifecycleStatus::Active,
        );
    }

    public function digest(Node $node): string
    {
        return hash('sha256', SourceDigest::of([
            'app/Infrastructure/Fleet/Footprint/PhpRuntimeFootprintArtifact.php',
            'app/Infrastructure/Nodes/RemotePhpPackageManager.php',
            'app/Infrastructure/Nodes/PhpFpmRuntimeIniRenderer.php',
            'app/Infrastructure/MachineSoftware/RecipeLoader.php',
            'app/Infrastructure/MachineSoftware/NodeRecipeProgram.php',
            'app/Domain/Nodes/UbuntuRelease.php',
        ])."\0".implode(',', $this->versions($node))."\0".new PhpFpmRuntimeIniRenderer()->render(RemotePhpPackageManager::runtimeProfile($node)));
    }

    /** @return list<string> */
    private function versions(Node $node): array
    {
        $recipe = $this->recipes->load();
        $versions = array_keys($recipe->phpExtensions($recipe->profile(RemotePhpPackageManager::runtimeProfile($node))));
        foreach ($node->instances()->where('status', InstanceState::Active)->get(['selected_php_version']) as $instance) {
            if ($instance->selected_php_version !== null) {
                $versions[] = $instance->selected_php_version;
            }
        }
        $versions = array_values(array_unique($versions));
        sort($versions, SORT_STRING);

        return $versions;
    }

    /** @return list<array<string, mixed>> */
    public function inputs(Node $node): array
    {
        return array_map(fn (string $version): array => $this->php->runtimeInput($node, $version), $this->versions($node));
    }

    public function apply(Node $node): bool
    {
        return $this->php->configureForAppNode($node, $this->versions($node));
    }
}

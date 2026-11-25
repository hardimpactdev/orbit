<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Nodes\NodeBootstrapPackageCatalog;
use App\Models\Node;

final readonly class ToolPackageProtection
{
    public function __construct(private NodeBootstrapPackageCatalog $bootstrapPackages) {}

    /** @return list<string> */
    public function forNode(Node $node, ToolManagerName $manager): array
    {
        if ($manager === ToolManagerName::Vp) {
            return ['pnpm', 'conn-server'];
        }
        if ($manager === ToolManagerName::Brew) {
            return ['wireguard-tools', 'wireguard-go', 'conn-server'];
        }
        if ($manager === ToolManagerName::BrewCask) {
            return ['conn-server'];
        }
        if ($manager !== ToolManagerName::Apt) {
            return [];
        }
        $recipe = new RecipeLoader()->source();
        $packages = [...$this->bootstrapPackages->forNode($node), 'openssh-server', 'wireguard-tools', 'conn-server', ...$recipe->gitPackages, $recipe->phpPolicy->rollbackTool];
        foreach (array_unique(array_merge(...array_values($recipe->profiles))) as $name) {
            $pin = $recipe->tools[$name];
            if ($pin->package !== null || in_array($name, RecipeLoader::IncusPackages, true)) {
                $packages[] = $pin->package ?? $name;
            }
        }

        return array_values(array_unique($packages));
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\Nodes\RoleName;
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
            return ['pnpm'];
        }
        if ($manager === ToolManagerName::Brew) {
            return ['wireguard-tools', 'wireguard-go'];
        }
        if ($manager !== ToolManagerName::Apt) {
            return [];
        }
        $packages = [...$this->bootstrapPackages->forNode($node), 'openssh-server', 'wireguard-tools', ...new RecipeLoader()->load()->gitPackages];
        foreach (RoleName::cases() as $role) {
            $packages = [...$packages, ...$this->bootstrapPackages->forRole($node, $role)];
        }

        return array_values(array_unique($packages));
    }
}

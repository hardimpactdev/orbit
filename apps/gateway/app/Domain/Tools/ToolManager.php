<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Models\Node;

interface ToolManager
{
    public function name(): ToolManagerName;

    public function supportsNode(Node $node): bool;

    public function validatePackage(string $package): bool;

    public function managerVersion(Node $node): string;

    public function candidateVersion(Node $node, string $package, ToolOperation $operation): ?string;

    /** One fresh observation of the complete installed manager scope. */
    public function installedInventory(Node $node): InstalledToolInventory;

    public function installedVersion(Node $node, string $package): ?string;

    public function normalizeVersion(string $rawVersion): ?string;

    public function planRemoval(Node $node, string $package): ToolRemovalPlan;
}

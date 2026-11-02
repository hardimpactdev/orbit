<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Tools\ToolInventoryPackage;
use App\Domain\Tools\ToolInventoryPackageKind;
use App\Domain\Tools\ToolInventoryScan;
use App\Domain\Tools\ToolInventoryScanState;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolPackageProtection;
use App\Models\Node;
use App\Models\Tool;

/** Reads existing dpkg observations; repository intent comes only from registered Tools. */
final readonly class AptInventoryInspector
{
    public function __construct(private AptToolManager $apt, private ToolPackageProtection $protection) {}

    public function inspect(Node $node): ToolInventoryScan
    {
        if ($node->platform !== 'linux') {
            return new ToolInventoryScan(ToolManagerName::Apt, ToolInventoryScanState::Unsupported, []);
        }
        try {
            $inventory = $this->apt->installedInventory($node);
        } catch (ToolManagerException) {
            return new ToolInventoryScan(ToolManagerName::Apt, ToolInventoryScanState::Incomplete, []);
        }
        $tools = Tool::query()->where('node_id', $node->id)
            ->whereHas('manager', static fn ($query) => $query->where('name', 'apt'))->with('aptRepository')->get()->keyBy('package');
        $protected = $this->protection->forNode($node, ToolManagerName::Apt);
        $packages = [];
        foreach ($inventory->packages() as $name) {
            try {
                $version = $inventory->versionFor($name);
            } catch (ToolManagerException) {
                $version = null;
            }
            $tool = $tools->get($name);
            $block = in_array($name, $protected, true) || $this->apt->roleOwns($name) ? ToolInventoryPackage::BLOCK_PROTECTED
                : (! $this->apt->validatePackage($name) ? ToolInventoryPackage::BLOCK_SOURCE : ($version === null ? ToolInventoryPackage::BLOCK_VERSION : null));
            $packages[] = new ToolInventoryPackage(
                manager: ToolManagerName::Apt, package: $name, packageKind: ToolInventoryPackageKind::Deb,
                installedVersion: $version === null ? null : $this->apt->normalizeVersion($version), dependency: false,
                registered: $tool !== null, toolId: $tool?->id,
                adoption: $block === null ? ToolInventoryPackage::SUPPORTED : ToolInventoryPackage::UNSUPPORTED,
                adoptionBlock: $block, repository: $tool?->aptRepository?->definition,
            );
        }
        usort($packages, static fn (ToolInventoryPackage $left, ToolInventoryPackage $right): int => $left->package <=> $right->package);

        return new ToolInventoryScan(ToolManagerName::Apt, ToolInventoryScanState::Complete, $packages);
    }
}

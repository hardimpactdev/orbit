<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolInventoryPackage;
use App\Domain\Tools\ToolInventoryPackageKind;
use App\Domain\Tools\ToolInventoryScan;
use App\Domain\Tools\ToolInventoryScanState;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Models\Node;
use App\Models\Tool;

/**
 * Reads the enrolled account's Vite+ global root packages.
 * The read takes no manager lock, refreshes no metadata, and creates no manager or Tool rows.
 */
final readonly class VpInventoryInspector
{
    private const string PROTECTED = 'pnpm';

    public function __construct(
        private VpToolManager $vp,
        private SemverVersionNormalizer $versions,
    ) {}

    public function inspect(Node $node): ToolInventoryScan
    {
        if ($node->platform !== 'linux' && $node->platform !== 'macos') {
            return $this->blank(ToolInventoryScanState::Unsupported);
        }

        $toolIds = $this->toolIds($node);

        try {
            $inventory = $this->vp->inventory($node);
        } catch (ToolManagerException $exception) {
            return $this->blank($this->stateFromScope($exception));
        }
        if (! $inventory['scan_complete']) {
            return $this->blank(ToolInventoryScanState::Incomplete);
        }
        $packages = [];
        foreach ($inventory['packages'] as $entry) {
            $name = $entry['name'];
            $manageable = $this->vp->validatePackage($name);
            $normalized = $entry['version'] === null ? null : $this->versions->normalize($entry['version']);
            $toolId = $manageable ? ($toolIds[$name] ?? null) : null;
            $block = $this->block($name, $normalized, $manageable);
            $packages[] = new ToolInventoryPackage(
                manager: ToolManagerName::Vp,
                package: $name,
                packageKind: ToolInventoryPackageKind::Global,
                installedVersion: $normalized,
                dependency: false,
                registered: $toolId !== null,
                toolId: $toolId,
                adoption: $block === null ? ToolInventoryPackage::SUPPORTED : ToolInventoryPackage::UNSUPPORTED,
                adoptionBlock: $block,
            );
        }
        usort($packages, static fn (ToolInventoryPackage $left, ToolInventoryPackage $right): int => $left->package <=> $right->package);

        return new ToolInventoryScan(ToolManagerName::Vp, ToolInventoryScanState::Complete, $packages);
    }

    private function block(string $package, ?string $version, bool $manageable): ?string
    {
        if (! $manageable) {
            return ToolInventoryPackage::BLOCK_SOURCE;
        }

        if ($package === self::PROTECTED) {
            return ToolInventoryPackage::BLOCK_PROTECTED;
        }

        if ($version === null) {
            return ToolInventoryPackage::BLOCK_VERSION;
        }

        return null;
    }

    private function stateFromScope(ToolManagerException $exception): ToolInventoryScanState
    {
        return match ($exception->step) {
            'manager-absent' => ToolInventoryScanState::Absent,
            'manager-conflict' => ToolInventoryScanState::Conflicting,
            default => ToolInventoryScanState::Incomplete,
        };
    }

    private function blank(ToolInventoryScanState $state): ToolInventoryScan
    {
        return new ToolInventoryScan(ToolManagerName::Vp, $state, []);
    }

    /**
     * Existing Vite+ Tool rows for this Node. Discoveries are not inserted.
     *
     * @return array<string, int>
     */
    private function toolIds(Node $node): array
    {
        if (! $node->exists) {
            return [];
        }

        $tools = Tool::query()
            ->where('node_id', $node->id)
            ->whereHas('manager', static function ($query): void {
                $query->where('name', ToolManagerName::Vp->value);
            })
            ->with('manager')
            ->get();

        $ids = [];

        foreach ($tools as $tool) {
            $manager = $tool->manager;

            if ($manager->node_id !== $node->id || $manager->name !== ToolManagerName::Vp->value) {
                continue;
            }

            $ids[$tool->package] = $tool->id;
        }

        return $ids;
    }
}

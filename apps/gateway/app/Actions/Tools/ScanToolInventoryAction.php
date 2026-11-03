<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ObservedPackages;
use App\Domain\Tools\ReservesRolePackages;
use App\Domain\Tools\ToolInventoryPackage;
use App\Domain\Tools\ToolInventoryPackageKind;
use App\Domain\Tools\ToolInventoryReport;
use App\Domain\Tools\ToolInventoryScan;
use App\Domain\Tools\ToolInventoryScanState;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Domain\Tools\ToolNodeEligibility;
use App\Domain\Tools\ToolOperationException;
use App\Domain\Tools\ToolOutcome;
use App\Domain\Tools\ToolPackageProtection;
use App\Models\Node;
use App\Models\Tool;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;

/**
 * Lists the installed packages of one Node from the Node agent's latest `packages` facts, joined with its recorded
 * Tools. It reads nothing on the Node and writes no Tool intent.
 */
final readonly class ScanToolInventoryAction
{
    private const array Managers = [ToolManagerName::Brew, ToolManagerName::BrewCask, ToolManagerName::Vp, ToolManagerName::Apt];

    public function __construct(
        private ToolManagerRegistry $managers,
        private ToolPackageProtection $protection,
        private ToolNodeEligibility $eligibility,
    ) {}

    public function execute(int $nodeId): ToolInventoryReport
    {
        $node = Node::query()->find($nodeId) ?? throw (new ModelNotFoundException)->setModel(Node::class, [$nodeId]);
        if ($node->status !== LifecycleStatus::Active) {
            throw $this->failure($node, 'tool.node_inactive', 'Tools can be scanned only on an active node.');
        }
        if (! $this->eligibility->allows($node)) {
            throw $this->failure($node, 'tool.node_unmanaged', 'Tools can be scanned only on a Gateway-managed node.');
        }
        $tools = Tool::query()->where('node_id', $node->id)->with('manager', 'aptRepository')->get()
            ->keyBy(static fn (Tool $tool): string => $tool->manager->name.'|'.$tool->package);
        $scans = [];
        $observedAt = null;
        foreach (self::Managers as $name) {
            $manager = $this->managers->find($name->value);
            $observed = $manager?->supportsNode($node) === true ? ObservedPackages::find($node, $name) : null;
            if ($manager === null || $observed === null) {
                $scans[] = new ToolInventoryScan($name, $manager?->supportsNode($node) === true ? ToolInventoryScanState::NotObserved : ToolInventoryScanState::Unsupported, []);

                continue;
            }
            $observedAt = $observedAt === null ? $observed->observedAt : min($observedAt, $observed->observedAt);
            $protected = $this->protection->forNode($node, $name);
            $packages = [];
            foreach ($observed->packages as $package) {
                $version = $package->version();
                $normalized = $version === null ? null : $manager->normalizeVersion($version);
                $tool = $tools->get($name->value.'|'.$package->name);
                $block = match (true) {
                    in_array($package->name, $protected, true) || ($manager instanceof ReservesRolePackages && $manager->roleOwns($package->name)) => ToolInventoryPackage::BLOCK_PROTECTED,
                    ! $manager->validatePackage($package->name) => ToolInventoryPackage::BLOCK_SOURCE,
                    default => $package->block,
                };
                $packages[] = new ToolInventoryPackage(
                    manager: $name, package: $package->name,
                    packageKind: match ($name) {
                        ToolManagerName::Apt => ToolInventoryPackageKind::Deb,
                        ToolManagerName::BrewCask => ToolInventoryPackageKind::Cask,
                        ToolManagerName::Brew => ToolInventoryPackageKind::Formula,
                        default => ToolInventoryPackageKind::Global,
                    },
                    installedVersion: $normalized, dependency: $package->dependency,
                    registered: $tool !== null, toolId: $tool?->id,
                    adoption: $block === null ? ToolInventoryPackage::SUPPORTED : ToolInventoryPackage::UNSUPPORTED,
                    adoptionBlock: $block, repository: $tool?->aptRepository?->definition,
                );
            }
            $scans[] = new ToolInventoryScan($name, ToolInventoryScanState::Complete, $packages);
        }

        return new ToolInventoryReport(
            nodeId: $node->id,
            observedAt: ($observedAt ?? Carbon::now())->utc()->format('Y-m-d\TH:i:sP'),
            managers: $scans,
        );
    }

    private function failure(Node $node, string $errorCode, string $message): ToolOperationException
    {
        return new ToolOperationException(
            step: 'scan', errorCode: $errorCode, outcome: ToolOutcome::ManagerFailed, status: 409,
            nodeId: $node->id, manager: '', package: '', versionConstraint: null, message: $message,
        );
    }
}

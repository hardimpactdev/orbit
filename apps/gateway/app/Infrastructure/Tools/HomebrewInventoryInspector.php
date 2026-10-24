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
use InvalidArgumentException;

/** Joins native Homebrew observations with this Node's recorded Tool intent. */
final readonly class HomebrewInventoryInspector
{
    public function __construct(private SemverVersionNormalizer $versions, private HomebrewCommand $homebrew) {}

    /** @return list<ToolInventoryScan> */
    public function inspect(Node $node): array
    {
        if (! in_array($node->platform, ['linux', 'macos'], true)) {
            return $this->unavailable(ToolInventoryScanState::Unsupported, false);
        }
        $mac = $node->platform === 'macos';
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '' || ($mac && ! in_array($node->architecture, ['arm64', 'x86_64'], true))) {
            return $this->unavailable(ToolInventoryScanState::Incomplete, $mac);
        }
        try {
            $data = $this->homebrew->run($node, 'tools.homebrew-scan', $mac ? ['architecture' => $node->architecture] : [], 'inventory');
        } catch (ToolManagerException $error) {
            $state = match ($error->step) {
                'manager-absent' => ToolInventoryScanState::Absent,
                'manager-conflict' => ToolInventoryScanState::Conflicting,
                default => ToolInventoryScanState::Incomplete,
            };

            return $this->unavailable($state, $mac);
        }
        $ids = $this->toolIds($node);

        return [
            $this->observations($data['formulae'] ?? null, ToolManagerName::Brew, $ids),
            $mac ? $this->observations($data['casks'] ?? null, ToolManagerName::BrewCask, $ids) : $this->blank(ToolManagerName::BrewCask, ToolInventoryScanState::Unsupported),
        ];
    }

    /** @param array<string, int> $ids */
    private function observations(mixed $data, ToolManagerName $manager, array $ids): ToolInventoryScan
    {
        if (! is_array($data) || ! is_string($data['state'] ?? null) || ! is_array($data['packages'] ?? null) || ! array_is_list($data['packages'])) {
            return $this->blank($manager, ToolInventoryScanState::Incomplete);
        }
        if ($data['state'] !== 'complete') {
            return $this->blank($manager, ToolInventoryScanState::Incomplete);
        }
        $packages = [];
        $seen = [];
        foreach ($data['packages'] as $entry) {
            if (! is_array($entry) || ! is_string($entry['name'] ?? null) || isset($seen[$entry['name']])
                || ! array_key_exists('installed_version', $entry) || ($entry['installed_version'] !== null && ! is_string($entry['installed_version']))
                || ! is_bool($entry['dependency'] ?? null) || ! array_key_exists('block', $entry) || ($entry['block'] !== null && ! is_string($entry['block']))) {
                return $this->blank($manager, ToolInventoryScanState::Incomplete);
            }
            $seen[$entry['name']] = true;
            $toolId = $ids[$this->key($manager, $entry['name'])] ?? null;
            try {
                $packages[] = new ToolInventoryPackage(
                    manager: $manager,
                    package: $entry['name'],
                    packageKind: $manager === ToolManagerName::Brew ? ToolInventoryPackageKind::Formula : ToolInventoryPackageKind::Cask,
                    installedVersion: $entry['installed_version'] === null ? null : $this->versions->normalize($entry['installed_version']),
                    dependency: $entry['dependency'],
                    registered: $toolId !== null,
                    toolId: $toolId,
                    adoption: $entry['block'] === null ? ToolInventoryPackage::SUPPORTED : ToolInventoryPackage::UNSUPPORTED,
                    adoptionBlock: $entry['block'],
                );
            } catch (InvalidArgumentException) {
                return $this->blank($manager, ToolInventoryScanState::Incomplete);
            }
        }
        usort($packages, static fn (ToolInventoryPackage $a, ToolInventoryPackage $b): int => $a->package <=> $b->package);

        return new ToolInventoryScan($manager, ToolInventoryScanState::Complete, $packages);
    }

    /** @return list<ToolInventoryScan> */
    private function unavailable(ToolInventoryScanState $state, bool $mac): array
    {
        return [$this->blank(ToolManagerName::Brew, $state), $this->blank(ToolManagerName::BrewCask, $mac ? $state : ToolInventoryScanState::Unsupported)];
    }

    private function blank(ToolManagerName $manager, ToolInventoryScanState $state): ToolInventoryScan
    {
        return new ToolInventoryScan($manager, $state, []);
    }

    /**
     * Existing Tool rows for this Node. Discoveries are not inserted.
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
                $query->whereIn('name', [
                    ToolManagerName::Brew->value,
                    ToolManagerName::BrewCask->value,
                ]);
            })
            ->with('manager')
            ->get();

        $ids = [];

        foreach ($tools as $tool) {
            $manager = $tool->manager;

            if ($manager->node_id !== $node->id) {
                continue;
            }

            $name = ToolManagerName::tryFrom($manager->name);

            if ($name !== ToolManagerName::Brew && $name !== ToolManagerName::BrewCask) {
                continue;
            }

            $ids[$this->key($name, $tool->package)] = $tool->id;
        }

        return $ids;
    }

    private function key(ToolManagerName $manager, string $package): string
    {
        return $manager->value.'|'.$package;
    }
}

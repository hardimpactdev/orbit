<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Domain\NodeFacts\NodeFactKind;
use App\Models\Node;
use App\Models\NodeFact;
use App\Support\ValidatedData;
use Carbon\CarbonImmutable;

/**
 * One Tool Manager's packages on a Node, from the Node agent's latest `packages` fact. Adoption and discovery read
 * only this; nothing reads the Node.
 */
final readonly class ObservedPackages
{
    /**
     * @param  array<string, ObservedPackage>  $packages  by name
     * @param  array<string, mixed>  $data
     */
    private function __construct(public array $packages, private array $data, public CarbonImmutable $observedAt) {}

    /**
     * Null until the agent has reported the manager, and for a fact without a `scope`, which reports that no state path
     * exists. Casks come from the Homebrew fact.
     */
    public static function find(Node $node, ToolManagerName $manager): ?self
    {
        $subject = $manager === ToolManagerName::BrewCask ? ToolManagerName::Brew : $manager;
        $fact = NodeFact::query()->where('node_id', $node->id)->where('kind', NodeFactKind::Packages->value)
            ->where('subject', $subject->value)->first();
        if (! $fact instanceof NodeFact || ($fact->data['scope'] ?? null) === null) {
            return null;
        }
        $data = $fact->data;
        $packages = [];
        foreach (self::rows($manager, $data) as $package) {
            $packages[$package->name] = $package;
        }
        ksort($packages);

        return new self($packages, $data, CarbonImmutable::instance($fact->observed_at));
    }

    /** The content of one reported apt source or keyring file, or null when the agent did not report it. */
    public function file(string $path): ?string
    {
        foreach ([...self::list($this->data['sources'] ?? []), ...self::list($this->data['keys'] ?? [])] as $file) {
            $file = ValidatedData::object($file);
            if (($file['path'] ?? null) === $path) {
                return base64_decode(ValidatedData::nullableString($file['content'] ?? null) ?? '', true) ?: '';
            }
        }

        return null;
    }

    /** @return list<mixed> */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<ObservedPackage>
     */
    private static function rows(ToolManagerName $manager, array $data): array
    {
        $roots = self::list($data['roots'] ?? []);
        $rows = [];
        foreach (self::list($data[match ($manager) {
            ToolManagerName::Brew => 'formulae',
            ToolManagerName::BrewCask => 'casks',
            default => 'packages',
        }] ?? []) as $row) {
            $row = ValidatedData::object($row);
            $name = ValidatedData::string($row['name'] ?? '');
            $versions = array_values(array_filter(self::list($row['versions'] ?? [$row['version'] ?? null]), is_string(...)));
            $dependency = match ($manager) {
                ToolManagerName::Composer => ! in_array($name, $roots, true),
                ToolManagerName::Brew => ($row['on_request'] ?? null) !== true,
                default => false,
            };
            $block = match (true) {
                $manager === ToolManagerName::Brew && ($row['tap'] ?? null) !== 'homebrew/core' => ToolInventoryPackage::BLOCK_SOURCE,
                // The fact carries no cask artifacts, so Orbit can't show that it could manage a cask later.
                $manager === ToolManagerName::BrewCask => ToolInventoryPackage::BLOCK_ARTIFACT,
                $dependency => ToolInventoryPackage::BLOCK_DEPENDENCY,
                count($versions) !== 1 || $versions[0] === 'latest' => ToolInventoryPackage::BLOCK_VERSION,
                default => null,
            };
            $rows[] = new ObservedPackage($name, $versions, $dependency, $block);
        }

        return $rows;
    }
}

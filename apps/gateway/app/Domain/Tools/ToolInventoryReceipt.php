<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use Closure;

/** Parses typed inventory receipts for both Gateway reads and Swamp result projection. */
final readonly class ToolInventoryReceipt
{
    /** @param array<string, mixed> $data
     * @param  Closure(string): bool|null  $validatePackage
     */
    public function parse(ToolManagerName $manager, array $data, ?Closure $validatePackage = null): InstalledToolInventory
    {
        if (! is_array($data['packages'] ?? null) || ! array_is_list($data['packages'])
            || ($manager === ToolManagerName::Vp && ! is_bool($data['scan_complete'] ?? null))) {
            throw new ToolManagerException('installed-version', 'The agent returned an incomplete Tool inventory.');
        }
        $versions = [];
        $unverifiable = [];
        $homebrew = in_array($manager, [ToolManagerName::Brew, ToolManagerName::BrewCask], true);
        foreach ($data['packages'] as $entry) {
            if (! is_array($entry) || ! is_string($entry['name'] ?? null)) {
                throw new ToolManagerException('installed-version', 'The agent returned an invalid package observation.');
            }
            $name = $entry['name'];
            if ($homebrew) {
                if (! is_array($entry['versions'] ?? null) || ! array_is_list($entry['versions'])
                    || ! array_all($entry['versions'], is_string(...)) || ! is_bool($entry['unverifiable'] ?? null)) {
                    throw new ToolManagerException('installed-version', 'The agent returned an invalid Homebrew observation.');
                }
                $versions[$name] = [...($versions[$name] ?? []), ...$entry['versions']];
                if ($entry['unverifiable']) {
                    $unverifiable[] = $name;
                }
            } else {
                if (! array_key_exists('version', $entry) || ($entry['version'] !== null && ! is_string($entry['version']))) {
                    throw new ToolManagerException('installed-version', 'The agent returned an invalid package version.');
                }
                if ($validatePackage !== null && ! $validatePackage($name)) {
                    continue;
                }
                if ($entry['version'] === null) {
                    $unverifiable[] = $name;
                } else {
                    $versions[$name][] = $entry['version'];
                }
            }
        }

        return new InstalledToolInventory($versions, unverifiablePackages: $unverifiable, complete: $manager !== ToolManagerName::Vp || $data['scan_complete'] === true);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

/** Resolve immutable deployment intent from Project data, never from registry tags. */
final readonly class VpProjectRequirements
{
    /** @param array<string, mixed> $configuration */
    public function resolve(array $configuration, string $store): ?VpEnvironment
    {
        $package = $configuration['package'] ?? [];
        if (! is_array($package)) {
            throw new InvalidArgumentException('Invalid Project manifest.');
        }
        $dependencies = [...(is_array($package['dependencies'] ?? null) ? $package['dependencies'] : []), ...(is_array($package['devDependencies'] ?? null) ? $package['devDependencies'] : [])];
        $names = array_keys(array_filter($dependencies, fn ($spec, $name): bool => $name === 'vite-plus' || (is_string($spec) && str_starts_with($spec, 'npm:vite-plus@')), ARRAY_FILTER_USE_BOTH));
        if ($names === []) {
            return null;
        }
        $versions = [];
        $nodeEngine = null;
        $lockedManager = null;
        $pnpm = $this->field($configuration, 'locks', 'pnpm-lock.yaml');
        $npm = $this->field($configuration, 'locks', 'package-lock.json');
        if (is_string($pnpm)) {
            $family = 'pnpm';
            $lock = Yaml::parse($pnpm);
            $lockedManager = $this->field($lock, 'packageManager');
            foreach ($names as $name) {
                foreach (['dependencies', 'devDependencies'] as $section) {
                    $entry = $this->field($lock, 'importers', '.', $section, (string) $name) ?? $this->field($lock, $section, (string) $name);
                    $value = is_array($entry) ? ($entry['version'] ?? null) : $entry;
                    if (is_string($value) && str_starts_with($value, 'link:')) {
                        $value = $this->field($configuration, 'workspaces', $value);
                    }
                    if (is_string($value)) {
                        $version = $this->lockedVersion($value);
                        $versions[] = $version;
                        $nodeEngine = $this->field($lock, 'packages', 'vite-plus@'.$version, 'engines', 'node');
                    }
                }
            }
        } elseif (is_string($npm)) {
            $family = 'npm';
            $lock = json_decode($npm, true, flags: JSON_THROW_ON_ERROR);
            $npmVersion = $this->field($lock, 'npmVersion');
            $lockedManager = is_string($npmVersion) ? 'npm@'.$npmVersion : $this->field($lock, 'packageManager');
            foreach ($names as $name) {
                $entry = $this->field($lock, 'packages', 'node_modules/'.$name) ?? $this->field($lock, 'dependencies', (string) $name);
                if ($this->field($entry, 'link') === true) {
                    $resolved = $this->field($entry, 'resolved');
                    $entry = is_string($resolved) ? $this->field($lock, 'packages', $resolved) : null;
                }
                $value = $this->field($entry, 'version');
                if (is_string($value)) {
                    $versions[] = $this->lockedVersion($value);
                    $nodeEngine = $this->field($entry, 'engines', 'node');
                }
            }
        } else {
            throw new InvalidArgumentException('A pnpm or npm lockfile with a locked vite-plus release is required.');
        }
        $versions = array_values(array_unique($versions));
        if (count($versions) !== 1) {
            throw new InvalidArgumentException('The lockfile must select one exact vite-plus release.');
        }
        $pin = $configuration['.node-version'] ?? $configuration['.nvmrc'] ?? $this->field($package, 'engines', 'node');
        $node = is_string($pin) ? ltrim(trim($pin), 'v') : '';
        $managerEngine = $this->field($package, 'engines', $family);
        $selector = $package['packageManager'] ?? $lockedManager ?? (is_string($managerEngine) ? $family.'@'.$managerEngine : '');
        if (! is_string($selector) || preg_match('/\A(npm|pnpm)@(.+?)(?:\+(sha(?:224|256|384|512)\.[a-f0-9]+))?\z/Di', $selector, $match) !== 1 || $match[1] !== $family) {
            throw new InvalidArgumentException('Pin an exact packageManager matching the lockfile.');
        }
        foreach ([$nodeEngine, $this->field($package, 'engines', 'node')] as $engine) {
            if ($engine !== null && (! is_string($engine) || ! (NpmVersionRange::parse($engine)?->satisfies($node) ?? false))) {
                throw new InvalidArgumentException('The exact Node pin must satisfy the locked vite-plus and Project engines.');
            }
        }
        if ($managerEngine !== null && (! is_string($managerEngine) || ! (NpmVersionRange::parse($managerEngine)?->satisfies($match[2]) ?? false))) {
            throw new InvalidArgumentException('The package manager pin must satisfy its Project engine.');
        }

        return new VpEnvironment($store, $versions[0], $node, $family, $match[2], $match[3] ?? null);
    }

    private function field(mixed $value, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        return $value;
    }

    private function lockedVersion(string $value): string
    {
        // Peer suffixes and alias/workspace prefixes in lock entries are resolutions, not moving selectors.
        $value = preg_replace('/\(.*\)\z/', '', $value) ?? '';
        $value = preg_replace('/\A(?:(?:npm:)?vite-plus@|workspace:)/', '', $value) ?? '';
        if (preg_match('/\A\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?\z/D', $value) !== 1) {
            throw new InvalidArgumentException('vite-plus must resolve to an exact locked release.');
        }

        return $value;
    }
}

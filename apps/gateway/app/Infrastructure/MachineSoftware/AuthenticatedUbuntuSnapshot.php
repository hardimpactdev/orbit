<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class AuthenticatedUbuntuSnapshot implements SnapshotAvailability
{
    public function __construct(
        private RecipeDownload $downloads = new RecipeDownload,
        private UbuntuReleaseSignature $signatures = new UbuntuReleaseSignature,
    ) {}

    public function verify(MachineRecipe $recipe): void
    {
        $packages = [];
        foreach ($recipe->tools as $name => $tool) {
            if ($tool->source === 'ubuntu') {
                $packages[] = $name;
            }
        }
        $versions = $this->latest($recipe->snapshot, $recipe->architectures, $packages);
        foreach ($versions as $name => $version) {
            if ($recipe->tools[$name]->version !== $version) {
                throw new RuntimeException("Tool [{$name}] version [{$recipe->tools[$name]->version}] architecture [{$recipe->architectures[0]}] unavailable in snapshot [{$recipe->snapshot}] as its newest version [{$version}]. Bump the snapshot and all Ubuntu pins together.");
            }
        }
    }

    public function latest(string $snapshot, array $architectures, array $packages): array
    {
        $root = "https://snapshot.ubuntu.com/ubuntu/{$snapshot}/dists/";
        $versions = null;
        foreach ($architectures as $architecture) {
            $found = array_fill_keys($packages, null);
            foreach (['resolute', 'resolute-updates', 'resolute-security'] as $pocket) {
                $url = $root.$pocket.'/InRelease';
                try {
                    $file = $this->downloads->file($url);
                    try {
                        $release = $this->signatures->verify($file);
                    } finally {
                        unlink($file);
                    }
                    // A valid signature from a different release is not enough.
                    if (! preg_match('/^Codename: resolute$/m', $release)
                        || ! preg_match('/^Suite: '.preg_quote($pocket, '/').'$/m', $release)
                        || ! preg_match('/^Architectures: .*\b'.preg_quote($architecture, '/').'\b/m', $release)) {
                        throw new RuntimeException('Signed metadata has the wrong release, pocket, or architecture.');
                    }
                    foreach (['main', 'universe'] as $component) {
                        $path = "{$component}/binary-{$architecture}/Packages.gz";
                        if (! preg_match('/^SHA256:\n((?: [^\n]+\n)+)/m', $release, $section)
                            || ! preg_match('/^ ([a-f0-9]{64})\s+(\d+) '.preg_quote($path, '/').'$/m', $section[1], $entry)) {
                            throw new RuntimeException("Signed SHA256 index entry missing for {$path}.");
                        }
                        $indexUrl = $root.$pocket.'/'.$path;
                        $index = $this->downloads->file($indexUrl);
                        try {
                            if (hash_file('sha256', $index) !== $entry[1] || filesize($index) !== (int) $entry[2]) {
                                throw new RuntimeException("Index digest/size mismatch at {$indexUrl}.");
                            }
                            $this->resolvePackages($index, $found);
                        } finally {
                            unlink($index);
                        }
                    }
                } catch (Throwable $exception) {
                    throw new RuntimeException("Snapshot [{$snapshot}] release [26.04] architecture [{$architecture}] metadata [{$url}]: {$exception->getMessage()}", previous: $exception);
                }
            }
            foreach ($found as $name => $version) {
                if ($version === null) {
                    throw new RuntimeException("Tool [{$name}] architecture [{$architecture}] unavailable in snapshot [{$snapshot}]; package not found in authenticated indexes.");
                }
            }
            if ($versions !== null && $versions !== $found) {
                throw new RuntimeException("Snapshot [{$snapshot}] Ubuntu versions differ across architectures at [{$architecture}].");
            }
            $versions = $found;
        }

        return $versions ?? throw new RuntimeException('Snapshot resolution needs at least one architecture.');
    }

    /** @param array<string, string|null> $found */
    private function resolvePackages(string $path, array &$found): void
    {
        $stream = gzopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot open compressed Ubuntu package index.');
        }
        $package = '';
        $version = '';
        try {
            while (($line = gzgets($stream)) !== false) {
                if (str_starts_with($line, 'Package: ')) {
                    $package = trim(substr($line, 9));
                } elseif (str_starts_with($line, 'Version: ')) {
                    $version = trim(substr($line, 9));
                } elseif (trim($line) === '') {
                    $this->selectVersion($found, $package, $version);
                    $package = $version = '';
                }
            }
            $this->selectVersion($found, $package, $version);
        } finally {
            gzclose($stream);
        }
    }

    /** @param array<string, string|null> $found */
    private function selectVersion(array &$found, string $package, string $version): void
    {
        if (! array_key_exists($package, $found) || $version === '') {
            return;
        }
        if ($found[$package] === null) {
            $found[$package] = $version;

            return;
        }
        $comparison = new Process(['dpkg', '--compare-versions', $version, 'gt', $found[$package]]);
        $exit = $comparison->run();
        if ($exit !== 0 && $exit !== 1) {
            throw new RuntimeException('Cannot compare authenticated Ubuntu package versions.');
        }
        if ($exit === 0) {
            $found[$package] = $version;
        }
    }
}

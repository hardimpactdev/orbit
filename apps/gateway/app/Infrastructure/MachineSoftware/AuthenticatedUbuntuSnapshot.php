<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

use RuntimeException;
use Throwable;

final readonly class AuthenticatedUbuntuSnapshot implements SnapshotAvailability
{
    public function __construct(
        private RecipeDownload $downloads = new RecipeDownload,
        private UbuntuReleaseSignature $signatures = new UbuntuReleaseSignature,
    ) {}

    public function verify(MachineRecipe $recipe): void
    {
        $root = "https://snapshot.ubuntu.com/ubuntu/{$recipe->snapshot}/dists/";
        foreach ($recipe->architectures as $architecture) {
            $missing = [];
            foreach ($recipe->tools as $name => $tool) {
                if ($tool->source === 'ubuntu') {
                    $missing[$name] = $tool->version;
                }
            }
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
                            $this->resolvePackages($index, $missing);
                        } finally {
                            unlink($index);
                        }
                    }
                } catch (Throwable $exception) {
                    throw new RuntimeException("Snapshot [{$recipe->snapshot}] release [26.04] architecture [{$architecture}] metadata [{$url}]: {$exception->getMessage()}", previous: $exception);
                }
            }
            if ($missing !== []) {
                $name = array_key_first($missing);
                throw new RuntimeException("Tool [{$name}] version [{$missing[$name]}] architecture [{$architecture}] unavailable in snapshot [{$recipe->snapshot}] release [26.04] at {$root}; exact package version not found in authenticated indexes.");
            }
        }
    }

    /** @param array<string, string> $missing */
    private function resolvePackages(string $path, array &$missing): void
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
                    if (($missing[$package] ?? null) === $version) {
                        unset($missing[$package]);
                    }
                    $package = $version = '';
                }
            }
            if (($missing[$package] ?? null) === $version) {
                unset($missing[$package]);
            }
        } finally {
            gzclose($stream);
        }
    }
}

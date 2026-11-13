<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Infrastructure\MachineSoftware\FolderPin;
use App\Infrastructure\MachineSoftware\InstanceFolders;
use App\Infrastructure\MachineSoftware\VitePlusHome;
use App\Infrastructure\MachineSoftware\VitePlusRuntimeLock;

/**
 * The two shared folders as a container host installs them (docs/reference/development-containers.md#shared-folders). A folder's
 * version is a digest of everything in it, so any change to a pin, a program path or a note is a new version beside the old one;
 * the host then flips the folder's `current` link to it.
 */
final readonly class InstanceFolderPlan
{
    /**
     * @return array{runtime: array{path: string, version: string, parts: list<array<string, mixed>>},
     *     agent: array{path: string, version: string, parts: list<array<string, mixed>>, notes: array<string, string>}}
     */
    public static function for(InstanceFolders $folders): array
    {
        $runtime = ['path' => InstanceHostIntent::RuntimeFolder, 'parts' => [...self::parts($folders->runtime), ...self::php($folders)]];
        if ($folders->vitePlus instanceof VitePlusHome) {
            $runtime = self::vitePlus($runtime, $folders->vitePlus);
        }
        $agent = ['path' => InstanceHostIntent::AgentFolder, 'parts' => self::parts($folders->agent), 'notes' => InstanceNotes::all()];

        return ['runtime' => ['version' => self::version($runtime), ...$runtime], 'agent' => ['version' => self::version($agent), ...$agent]];
    }

    /**
     * @param  array<string, FolderPin>  $pins
     * @return list<array{name: string, version: string, url: string, archive: string, sha256?: string, sha512?: string, bin: array<string, string>}>
     */
    private static function parts(array $pins): array
    {
        $parts = [];
        foreach ($pins as $name => $pin) {
            $path = (string) parse_url($pin->artifact->location, PHP_URL_PATH);
            // A tarball, zip or Debian package is unpacked into the part's folder; any other artifact is the part's one file. A
            // package's libraries and fonts also go into the folder's `lib` and `fonts` (docs/reference/development-containers.md#browser-libraries-and-fonts).
            $archive = match (true) {
                preg_match('/\.(?:tar\.gz|tgz|tar\.xz)$/', $path) === 1 => 'tar',
                str_ends_with($path, '.zip') => 'zip',
                str_ends_with($path, '.deb') => 'deb',
                default => 'file',
            };
            $base = ['name' => (string) $name, 'version' => $pin->version, 'url' => $pin->artifact->location, 'archive' => $archive];
            $parts[] = $pin->artifact->algorithm === 'sha256'
                ? [...$base, 'sha256' => $pin->artifact->digest, 'bin' => $pin->bin]
                : [...$base, 'sha512' => $pin->artifact->digest, 'bin' => $pin->bin];
        }

        return $parts;
    }

    /**
     * Each published static PHP build as the part `php/<version>`, which unpacks to `php/<version>/bin/{php,frankenphp}`. Its
     * programs stay out of the folder's `bin`: an Instance picks its PHP version. The install checks both binaries against
     * `static_php`. A build whose SHA-256 the recipe does not record yet has no bytes to install.
     *
     * @return list<array<string, mixed>>
     */
    private static function php(InstanceFolders $folders): array
    {
        $parts = [];
        foreach ($folders->php as $version => $build) {
            if ($build->artifact === null) {
                continue;
            }
            $parts[] = ['name' => "php/{$version}", 'version' => $build->phpVersion, 'url' => $build->artifact->location, 'archive' => 'tar',
                'sha256' => $build->artifact->digest, 'bin' => [],
                'static_php' => ['php_version' => $build->phpVersion, 'extensions' => $build->extensions]];
        }

        return $parts;
    }

    /**
     * The Vite+ part gives the folder no program of its own: its `vp` goes into a home that the host builds once at the stable path
     * `vite-plus/<digest>`, because the home holds absolute paths. Each folder version links `vite-plus-home` to it, and its `bin/vp`
     * runs `vp` from that home, which `VP_HOME` names in every container.
     *
     * @param  array{path: string, parts: list<array<string, mixed>>}  $runtime
     * @return array{path: string, parts: list<array<string, mixed>>, vite_plus: array<string, string>}
     */
    private static function vitePlus(array $runtime, VitePlusHome $home): array
    {
        $runtime['parts'] = array_map(static fn (array $part): array => $part['name'] === $home->part ? [...$part, 'bin' => []] : $part, $runtime['parts']);
        $lock = VitePlusRuntimeLock::read($home->vp);
        // The home is a digest of what goes into it: the `vp` release, the locked package and the Node release.
        $release = array_values(array_filter($runtime['parts'], static fn (array $part): bool => $part['name'] === $home->part));
        $digest = hash('sha256', json_encode([$release, $home->binary, hash('sha256', $lock), $home->node], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return [...$runtime, 'vite_plus' => ['part' => $home->part, 'binary' => $home->binary, 'home' => InstanceHostIntent::RuntimeFolder.'/vite-plus/'.substr($digest, 0, 12),
            'version' => $home->vp->version, 'node' => $home->node, 'lock' => $lock]];
    }

    /** @param  array<string, mixed>  $folder */
    private static function version(array $folder): string
    {
        return substr(hash('sha256', json_encode($folder, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 0, 12);
    }
}

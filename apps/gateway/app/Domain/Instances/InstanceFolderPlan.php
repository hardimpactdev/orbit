<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Infrastructure\MachineSoftware\FolderPin;
use App\Infrastructure\MachineSoftware\InstanceFolders;

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
            // A tarball or zip is unpacked into the part's folder; any other artifact is the part's one file.
            $archive = match (true) {
                preg_match('/\.(?:tar\.gz|tgz|tar\.xz)$/', $path) === 1 => 'tar',
                str_ends_with($path, '.zip') => 'zip',
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

    /** @param  array<string, mixed>  $folder */
    private static function version(array $folder): string
    {
        return substr(hash('sha256', json_encode($folder, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 0, 12);
    }
}

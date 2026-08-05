<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Models\Node;

/**
 * @mago-expect lint:too-many-methods
 */
final class NodeHostPaths
{
    private const string USER_PATH_PATTERN = '#^/(?:home|Users)/(?<user>(?!\.{1,2}(?:/|$))[A-Za-z0-9._-]+)/(?<path>(?!\.{1,2}$)(?!\.{1,2}/)(?!.*(?:^|/)\.\.(?:/|$)).+)$#';

    public function homeDirectory(Node $node): string
    {
        return self::homeDirectoryFor($node->platform, $node->user);
    }

    public function packagesDirectory(Node $node): string
    {
        return $this->homeDirectory($node).'/packages';
    }

    public function userConfigRoot(Node $node): string
    {
        return $this->homeDirectory($node).'/.config/orbit';
    }

    public function appRuntimeConfigPath(Node $node, string $appSlug): string
    {
        return "{$this->userConfigRoot($node)}/apps/{$appSlug}.ini";
    }

    public function workspaceRuntimeConfigPath(Node $node, string $appSlug, string $workspaceSlug): string
    {
        return "{$this->userConfigRoot($node)}/workspaces/{$appSlug}-{$workspaceSlug}.ini";
    }

    public function runtimeTrustPoolPath(Node $node): string
    {
        return "{$this->userConfigRoot($node)}/ca/root.crt";
    }

    public function processDataRoot(Node $node, string $processName): string
    {
        if (self::isMacosPlatform($node->platform)) {
            return $this->homeDirectory($node).'/.local/share/orbit/processes/'.$processName;
        }

        return "/var/lib/orbit/processes/{$processName}";
    }

    public static function homeDirectoryFor(?string $platform, ?string $user): string
    {
        $normalizedUser = self::normalizeUser($user);

        if (self::isMacosPlatform($platform)) {
            // macOS root home is /var/root, not /Users/root.
            return $normalizedUser === 'root' ? '/var/root' : "/Users/{$normalizedUser}";
        }

        if ($normalizedUser === 'root') {
            return '/root';
        }

        return "/home/{$normalizedUser}";
    }

    public static function userForPackagesDirectory(string $source): ?string
    {
        $match = self::safeUserPath($source);

        if ($match === null || $match['path'] !== 'packages') {
            return null;
        }

        return $match['user'];
    }

    /**
     * @return array{user: string, path: string}|null
     */
    public static function safeUserPath(string $source): ?array
    {
        $matches = [];

        if (preg_match(self::USER_PATH_PATTERN, $source, $matches) !== 1) {
            return null;
        }

        return [
            'user' => $matches['user'],
            'path' => $matches['path'],
        ];
    }

    public static function isMacosPlatform(?string $platform): bool
    {
        if (! is_string($platform) || trim($platform) === '') {
            return false;
        }

        $family = strtolower(explode(separator: '_', string: trim($platform), limit: 2)[0]);

        return in_array(needle: $family, haystack: ['darwin', 'macos'], strict: true);
    }

    private static function normalizeUser(?string $user): string
    {
        $normalized = is_string($user) ? trim($user) : '';

        return $normalized !== '' ? $normalized : 'orbit';
    }
}

<?php

declare(strict_types=1);

namespace App\Data\Install;

final readonly class InstallContext
{
    /**
     * @param  array<int, string>  $phpVersions
     */
    public function __construct(
        public string $tld = 'test',
        public array $phpVersions = ['8.4', '8.5'],
        public bool $skipDocker = false,
        public bool $skipTrust = false,
        public bool $nonInteractive = false,
        public string $configDir = '',
        public string $homeDir = '',
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public static function fromOptions(array $options): self
    {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '/tmp';

        $phpVersionsOption = $options['php-versions'] ?? '8.4,8.5';
        $phpVersions = is_array($phpVersionsOption)
            ? $phpVersionsOption
            : array_map(trim(...), explode(',', (string) $phpVersionsOption));

        return new self(
            tld: (string) ($options['tld'] ?? 'test'),
            phpVersions: array_map(fn ($v) => self::normalizePhpVersion((string) $v), $phpVersions),
            skipDocker: (bool) ($options['skip-docker'] ?? false),
            skipTrust: (bool) ($options['skip-trust'] ?? false),
            nonInteractive: (bool) ($options['yes'] ?? false),
            configDir: "{$home}/.config/orbit",
            homeDir: $home,
        );
    }

    /**
     * Normalize PHP version to X.Y format (e.g., "84" -> "8.4", "8.4" -> "8.4")
     */
    private static function normalizePhpVersion(string $version): string
    {
        // Remove php@ prefix if present
        $version = str_replace(['php@', 'php'], '', $version);

        // If no dot, add one (84 -> 8.4)
        if (! str_contains($version, '.')) {
            $version = substr($version, 0, 1).'.'.substr($version, 1);
        }

        return $version;
    }
}

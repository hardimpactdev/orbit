<?php

declare(strict_types=1);

namespace App\Services\Version;

use Carbon\CarbonImmutable;
use JsonException;
use Throwable;

final class InstallMetadataStore
{
    private const int SchemaVersion = 1;

    /**
     * @return array{
     *     schema_version: int|null,
     *     version: string|null,
     *     installed_at: string|null,
     *     binary_path: string|null,
     *     install_root: string|null,
     * }|null
     */
    public function read(): ?array
    {
        $path = $this->path();

        if ($path === null || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        if ($contents === false || trim($contents) === '') {
            return null;
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        return [
            'schema_version' => is_int($decoded['schema_version'] ?? null) ? $decoded['schema_version'] : null,
            'version' => is_string($decoded['version'] ?? null) ? $decoded['version'] : null,
            'installed_at' => is_string($decoded['installed_at'] ?? null) ? $decoded['installed_at'] : null,
            'binary_path' => is_string($decoded['binary_path'] ?? null) ? $decoded['binary_path'] : null,
            'install_root' => is_string($decoded['install_root'] ?? null) ? $decoded['install_root'] : null,
        ];
    }

    public function installedAtFor(string $version): ?string
    {
        $metadata = $this->read();

        if ($metadata !== null && ($metadata['version'] ?? null) === $version && $this->isIsoTimestamp($metadata['installed_at'] ?? null)) {
            return CarbonImmutable::parse($metadata['installed_at'])->toIso8601String();
        }

        return $this->fallbackBinaryMtime();
    }

    public function write(string $version, string $binaryPath, string $installRoot, ?CarbonImmutable $installedAt = null): void
    {
        $path = $this->path();

        if ($path === null) {
            return;
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0700, recursive: true) && ! is_dir($directory)) {
            return;
        }

        @chmod($directory, 0700);

        $payload = [
            'schema_version' => self::SchemaVersion,
            'version' => $version,
            'installed_at' => ($installedAt ?? CarbonImmutable::now())->toIso8601String(),
            'binary_path' => $binaryPath,
            'install_root' => $installRoot,
        ];

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $tempPath = $path.'.tmp.'.bin2hex(random_bytes(8));

        if (file_put_contents($tempPath, $encoded, LOCK_EX) === false) {
            return;
        }

        @chmod($tempPath, 0600);

        if (! @rename($tempPath, $path)) {
            @unlink($tempPath);
        }
    }

    public function path(): ?string
    {
        $override = getenv('ORBIT_INSTALL_METADATA_PATH');

        if (is_string($override) && $override !== '') {
            return $override;
        }

        $home = getenv('HOME');

        if (! is_string($home) || $home === '') {
            return null;
        }

        return rtrim($home, '/').'/.config/orbit/install.json';
    }

    private function fallbackBinaryMtime(): ?string
    {
        $path = $this->binaryPath();

        if ($path === null || ! is_file($path)) {
            return null;
        }

        $mtime = filemtime($path);

        if ($mtime === false) {
            return null;
        }

        return CarbonImmutable::createFromTimestamp($mtime)->toIso8601String();
    }

    private function binaryPath(): ?string
    {
        $configured = getenv('ORBIT_BIN_PATH');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $argvPath = $this->existingPath($_SERVER['argv'][0] ?? null);

        if ($argvPath !== null) {
            return $argvPath;
        }

        $home = getenv('HOME');

        if (is_string($home) && $home !== '') {
            $userLocalPath = rtrim($home, '/').'/.local/bin/orbit';

            if (is_file($userLocalPath)) {
                return $userLocalPath;
            }
        }

        if (is_file('/usr/local/bin/orbit')) {
            return '/usr/local/bin/orbit';
        }

        return null;
    }

    private function existingPath(mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        return is_file($path) ? $path : null;
    }

    private function isIsoTimestamp(mixed $value): bool
    {
        if (! is_string($value) || trim($value) === '') {
            return false;
        }

        try {
            CarbonImmutable::parse($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Tasks\SwampReaderCredential;
use Dotenv\Dotenv;
use RuntimeException;
use Throwable;

/** Writes hold the Gateway release lock. A read needs no lock, because a write publishes by one atomic rename (NestedFleetClubKey reads it at each nested run start). */
final readonly class SwampReaderEnvironment
{
    private const array Keys = ['ORBIT_SWAMP_READ_TOKEN', 'ORBIT_SWAMP_READ_TOKEN_NAME', 'ORBIT_SWAMP_READ_TOKEN_EXPIRES_AT'];

    public function __construct(
        private GatewayReleaseLayout $layout,
        private string $guestProjection = '/opt/orbit-gateway-environment',
    ) {}

    public function contents(): string
    {
        $path = $this->layout->environmentPath();
        clearstatcache(true, $path);
        if (is_link($path) && readlink($path) === $this->guestProjection.'/gateway.env') {
            $this->privateDirectory($this->guestProjection);
            $path = $this->guestProjection.'/gateway.env';
            clearstatcache(true, $path);
        }
        $stat = @lstat($path);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 07777) !== 0600
            || $stat['uid'] !== posix_geteuid() || $stat['size'] > 1_048_576) {
            throw new RuntimeException('The shared Gateway env must be a private, owned regular file.');
        }
        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Cannot read the shared Gateway env.');
        }
        $this->values($contents);

        return $contents;
    }

    /** @return array<string, string|null> */
    public function values(#[\SensitiveParameter] string $contents): array
    {
        foreach (self::Keys as $key) {
            if (preg_match_all('/^\h*(?:export\h+)?'.preg_quote($key, '/').'\h*=/m', $contents) > 1) {
                throw new RuntimeException('The shared Gateway env has duplicate reader settings.');
            }
        }
        try {
            return Dotenv::parse($contents);
        } catch (Throwable) {
            throw new RuntimeException('The shared Gateway env is invalid.');
        }
    }

    public function install(#[\SensitiveParameter] string $original, SwampReaderCredential $credential): string
    {
        $values = $this->values($original);
        if (($values['ORBIT_SWAMP_READ_TOKEN_NAME'] ?? null) === $credential->name
            || ($values['ORBIT_SWAMP_READ_TOKEN'] ?? null) === $credential->token()) {
            throw new RuntimeException('Mint a replacement under a new name before installing it.');
        }

        return $this->replace($original, array_combine(self::Keys, [$credential->token(), $credential->name, $credential->expiresAt->format('Y-m-d\TH:i:s.u\Z')]));
    }

    /**
     * Replace or add only `$settings` in the shared env, after a private backup, in one atomic rename. Every other setting stays.
     *
     * @param  array<string, string>  $settings
     */
    public function replace(#[\SensitiveParameter] string $original, #[\SensitiveParameter] array $settings): string
    {
        $values = $this->values($original);
        foreach (array_keys($settings) as $key) {
            if (preg_match_all('/^\h*(?:export\h+)?'.preg_quote($key, '/').'\h*=/m', $original) > 1) {
                throw new RuntimeException('The shared Gateway env has duplicate settings.');
            }
        }
        $updated = $original;
        foreach ($settings as $key => $value) {
            $pattern = '/^\h*(?:export\h+)?'.preg_quote($key, '/').'\h*=[^\r\n]*(?:\r?\n|$)/m';
            $line = $key.'='.$value."\n";
            $updated = preg_match($pattern, $updated) === 1
                ? (string) preg_replace_callback($pattern, static fn (): string => $line, $updated)
                : rtrim($updated, "\r\n")."\n".$line;
        }
        $parsed = $this->values($updated);
        foreach ($settings as $key => $value) {
            if (($parsed[$key] ?? null) !== $value) {
                throw new RuntimeException('The settings cannot be replaced safely.');
            }
        }
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $settings) && ($parsed[$key] ?? null) !== $value) {
                throw new RuntimeException('The edit would change another Gateway setting.');
            }
        }
        $path = $this->layout->environmentPath();
        if (is_link($path)) {
            throw new RuntimeException('Install Gateway credentials on the host.');
        }
        $suffix = now()->utc()->format('Ymd\THis\Z').'-'.bin2hex(random_bytes(8));
        $backup = $path.'.before-swamp-'.$suffix;
        $temporary = dirname($path).'/.gateway.env.swamp-'.$suffix;
        $this->writePrivate($backup, $original);
        try {
            $this->writePrivate($temporary, $updated);
            $this->publish($temporary, $path, $original);
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        return $backup;
    }

    private function privateDirectory(string $directory): void
    {
        clearstatcache(true, $directory);
        $stat = @lstat($directory);
        if ($stat === false || ($stat['mode'] & 0177777) !== 0040700 || $stat['uid'] !== posix_geteuid()) {
            throw new RuntimeException('The Gateway environment projection must be a private, owned directory.');
        }
    }

    private function publish(string $temporary, string $path, #[\SensitiveParameter] string $original): void
    {
        $directory = dirname($path).'/.gateway-release-environment';
        clearstatcache(true, $directory);
        if (! file_exists($directory) && ! is_link($directory) && ! $this->layout->usesContainers()) {
            if (! @rename($temporary, $path)) {
                throw new RuntimeException('Cannot publish the host settings.');
            }

            return;
        }

        $this->privateDirectory($directory);
        $lock = @fopen($directory, 'r');
        if ($lock === false) {
            throw new RuntimeException('Cannot open the Gateway environment projection.');
        }
        $staged = $temporary.'.projection';
        try {
            // The host refresh helper locks the same directory before selecting the current inode.
            $folder = fstat($lock);
            $candidate = stat($temporary);
            if ($folder === false || $candidate === false || $folder['dev'] !== $candidate['dev']) {
                throw new RuntimeException('The Gateway projection must share the host settings filesystem.');
            }
            if (! flock($lock, LOCK_EX) || ! @link($temporary, $staged)) {
                throw new RuntimeException('Cannot stage the Gateway environment projection.');
            }
            if (! @rename($temporary, $path)) {
                throw new RuntimeException('Cannot publish the host settings.');
            }
            if (! @rename($staged, $directory.'/gateway.env')) {
                $this->writePrivate($temporary, $original);
                if (! @rename($temporary, $path)) {
                    throw new RuntimeException('Restore the protected Gateway backup and refresh the projection.');
                }
                throw new RuntimeException('Cannot publish the projected settings.');
            }
        } finally {
            if (is_file($staged)) {
                @unlink($staged);
            }
            fclose($lock);
        }
    }

    private function writePrivate(string $path, #[\SensitiveParameter] string $contents): void
    {
        $mask = umask(0077);
        try {
            $handle = @fopen($path, 'x');
        } finally {
            umask($mask);
        }
        if ($handle === false) {
            throw new RuntimeException('Cannot create a private reader settings file.');
        }
        try {
            if (fwrite($handle, $contents) !== strlen($contents) || ! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('Cannot write the private reader settings file.');
            }
        } finally {
            fclose($handle);
        }
    }
}

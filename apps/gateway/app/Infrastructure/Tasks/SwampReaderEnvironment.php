<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\Tasks\SwampReaderCredential;
use Dotenv\Dotenv;
use RuntimeException;
use Throwable;

/** Accessed only while the Gateway release lock is held. */
final readonly class SwampReaderEnvironment
{
    private const array Keys = ['ORBIT_SWAMP_READ_TOKEN', 'ORBIT_SWAMP_READ_TOKEN_NAME', 'ORBIT_SWAMP_READ_TOKEN_EXPIRES_AT'];

    public function __construct(private GatewayReleaseLayout $layout) {}

    public function contents(): string
    {
        $path = $this->layout->environmentPath();
        clearstatcache(true, $path);
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
        $updated = $original;
        $settings = array_combine(self::Keys, [$credential->token(), $credential->name, $credential->expiresAt->format('Y-m-d\TH:i:s.u\Z')]);
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
                throw new RuntimeException('The reader settings cannot be replaced safely.');
            }
        }
        foreach ($values as $key => $value) {
            if (! in_array($key, self::Keys, true) && ($parsed[$key] ?? null) !== $value) {
                throw new RuntimeException('The reader edit would change another Gateway setting.');
            }
        }
        $path = $this->layout->environmentPath();
        $suffix = now()->utc()->format('Ymd\THis\Z').'-'.bin2hex(random_bytes(8));
        $backup = $path.'.before-swamp-'.$suffix;
        $temporary = dirname($path).'/.gateway.env.swamp-'.$suffix;
        $this->writePrivate($backup, $original);
        try {
            $this->writePrivate($temporary, $updated);
            if (! @rename($temporary, $path)) {
                throw new RuntimeException('Cannot publish the reader settings; the backup is retained.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }

        return $backup;
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

<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Models\GatewayRelease;
use Illuminate\Support\Facades\Config;
use PDO;
use Throwable;

/** A verified private copy outside ordinary pre-release snapshot retention. */
final readonly class GatewayReleaseRecovery
{
    /** @return array{outcome: string, original: string, path: string, sha256: string} */
    public function protect(GatewayRelease $record): array
    {
        $saved = $record->phases['recovery'] ?? null;
        if (is_array($saved)) {
            $this->verify($record);

            return ['outcome' => 'verified', 'original' => $this->text($saved, 'original'), 'path' => $this->text($saved, 'path'), 'sha256' => $this->text($saved, 'sha256')];
        }
        $source = $record->snapshot_path;
        $directory = rtrim(Config::string('orbit.home'), '/').'/recovery';
        if (! is_string($source) || ! is_file($source) || is_link($source)
            || is_link($directory) || (! is_dir($directory) && ! mkdir($directory, 0700))) {
            $this->refuse();
        }
        $this->privateOwned($directory);
        $this->standalone($source);
        $target = $directory.'/stage-'.$record->id.'-'.$record->sha.'.sqlite';
        $hash = hash_file('sha256', $source);
        if (! is_string($hash)) {
            $this->refuse();
        }
        if (! file_exists($target) && ! is_link($target)) {
            $partial = $target.'.'.bin2hex(random_bytes(8)).'.partial';
            $output = fopen($partial, 'x+b');
            if ($output === false) {
                $this->refuse();
            }
            try {
                if (! chmod($partial, 0600)) {
                    $this->refuse();
                }
                $input = fopen($source, 'rb');
                if ($input === false) {
                    $this->refuse();
                }
                try {
                    if (stream_copy_to_stream($input, $output) === false || ! fflush($output) || ! fsync($output)) {
                        $this->refuse();
                    }
                } finally {
                    fclose($input);
                }
                $this->check($partial, $hash);
                if (! rename($partial, $target)) {
                    $this->refuse();
                }
            } finally {
                fclose($output);
                if (is_file($partial)) {
                    unlink($partial);
                }
            }
        }
        $this->check($target, $hash);

        return ['outcome' => 'verified', 'original' => $source, 'path' => $target, 'sha256' => $hash];
    }

    public function verify(GatewayRelease $record): void
    {
        $saved = $record->phases['recovery'] ?? null;
        $directory = rtrim(Config::string('orbit.home'), '/').'/recovery';
        if (! is_array($saved) || ($saved['outcome'] ?? null) !== 'verified'
            || ($saved['path'] ?? null) !== $directory.'/stage-'.$record->id.'-'.$record->sha.'.sqlite'
            || ! is_string($saved['sha256'] ?? null)) {
            $this->refuse();
        }
        $this->privateOwned($directory);
        $this->check($saved['path'], $saved['sha256']);
    }

    private function check(string $path, string $hash): void
    {
        $this->privateOwned($path);
        $this->standalone($path);
        if (! is_file($path) || ! hash_equals($hash, (string) hash_file('sha256', $path))) {
            $this->refuse();
        }
        try {
            $database = new PDO('sqlite:file:'.rawurlencode($path).'?mode=ro&immutable=1', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $database->exec('PRAGMA query_only=ON');
            $integrity = $database->query('PRAGMA integrity_check');
            if ($integrity === false || $integrity->fetchAll(PDO::FETCH_COLUMN) !== ['ok']) {
                $this->refuse();
            }
            $foreignKeys = $database->query('PRAGMA foreign_key_check');
            if ($foreignKeys === false) {
                $this->refuse();
            }
            $violations = [];
            while (count($violations) < 20 && ($row = $foreignKeys->fetch(PDO::FETCH_ASSOC)) !== false) {
                $violations[] = $row;
            }
            if ($violations !== []) {
                throw new GatewayReleaseException('recovery', 'gateway.release_recovery_foreign_keys',
                    'The original snapshot has foreign-key violations. Keep consumers held; repair the source data before a new stage or recover a verified complete recovery point. Never edit the protected snapshot.',
                    phase: ['violations' => $violations, 'limit' => 20]);
            }
        } catch (GatewayReleaseException $exception) {
            throw $exception;
        } catch (Throwable) {
            $this->refuse();
        }
    }

    private function standalone(string $path): void
    {
        foreach (['-wal', '-journal'] as $suffix) {
            clearstatcache(true, $path.$suffix);
            if (is_link($path.$suffix) || (file_exists($path.$suffix) && filesize($path.$suffix) !== 0)) {
                throw new GatewayReleaseException('recovery', 'gateway.release_recovery_invalid', 'Recovery requires a standalone SQLite snapshot, without a live WAL or journal. Keep the hold.');
            }
        }
    }

    private function privateOwned(string $path): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || ! file_exists($path) || fileowner($path) !== posix_geteuid() || (fileperms($path) & 0077) !== 0) {
            $this->refuse();
        }
    }

    /** @param array<mixed> $values */
    private function text(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (! is_string($value)) {
            $this->refuse();
        }

        return $value;
    }

    private function refuse(): never
    {
        throw new GatewayReleaseException('recovery', 'gateway.release_recovery_invalid', 'The original staged recovery copy could not be privately preserved and verified. Keep the hold.');
    }
}

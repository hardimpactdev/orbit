<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseDatabase;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Before Laravel's migrator runs, VACUUM INTO writes a consistent, private SQLite snapshot.
 * Laravel discovers and applies migrations with the prepared release's code, including vendor paths.
 */
final readonly class SqliteGatewayReleaseDatabase implements GatewayReleaseDatabase
{
    /** The default number of pre-release snapshots kept in `$ORBIT_HOME/backups`, newest first. */
    public const int KeptSnapshots = 3;

    /** @var Closure(string): (float|false) */
    private Closure $freeSpace;

    /**
     * @param  int  $keptSnapshots  `ORBIT_GATEWAY_RELEASE_SNAPSHOTS_KEEP`
     * @param  int  $minimumFreeBytes  The free space a snapshot leaves on the backup file system.
     * @param  (Closure(string): (float|false))|null  $freeSpace
     */
    public function __construct(
        private ProcessRunner $processes,
        private string $orbitHome,
        private string $php = '/usr/bin/php8.5',
        private float $migrateTimeout = 900.0,
        private ?string $connection = null,
        private int $keptSnapshots = self::KeptSnapshots,
        private int $minimumFreeBytes = GatewayReleaseBuilder::MinimumFreeBytes,
        ?Closure $freeSpace = null,
        private ?string $stepLock = null,
    ) {
        $this->freeSpace = $freeSpace ?? static fn (string $path): float|false => @disk_free_space($path);
    }

    /**
     * The live database file and its WAL. `VACUUM INTO` writes at most the pages in use, so this
     * bounds the snapshot from above.
     */
    public function snapshotBytes(): int
    {
        $database = DB::connection($this->connection)->getConfig('database');

        if (! is_string($database) || $database === '' || $database === ':memory:') {
            return 0;
        }

        $bytes = 0;

        foreach ([$database, $database.'-wal'] as $file) {
            $size = is_file($file) ? @filesize($file) : false;
            $bytes += $size === false ? 0 : $size;
        }

        return $bytes;
    }

    public function snapshot(string $id): string
    {
        $id = GatewayReleaseCommit::assertId($id);
        $connection = DB::connection($this->connection);

        if ($connection->getDriverName() !== 'sqlite') {
            throw new GatewayReleaseException(
                step: 'snapshot',
                errorCode: 'gateway.release_snapshot_unavailable',
                message: 'Only a SQLite Gateway database can be snapshotted before a release migrates.',
                status: 500,
            );
        }

        $directory = rtrim($this->orbitHome, '/').'/backups';

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw $this->snapshotFailed("The backup directory [{$directory}] cannot be created.");
        }

        // Every attempt gets its own file. A retry after a half-failed migration must not replace the clean copy.
        $target = $directory.'/pre-'.$id.'-'.gmdate('Ymd\THis\Z').'-'.bin2hex(random_bytes(3)).'.sqlite';
        $partial = $target.'.partial';
        @unlink($partial);
        $this->assertRoom($directory);

        try {
            $connection->statement('VACUUM INTO ?', [$partial]);
        } catch (Throwable $exception) {
            @unlink($partial);

            throw $this->snapshotFailed('VACUUM INTO failed: '.$exception->getMessage(), $exception);
        }

        if (! @chmod($partial, 0600) || ! @rename($partial, $target)) {
            @unlink($partial);

            throw $this->snapshotFailed("The snapshot [{$target}] cannot be put in place.");
        }

        $this->prune($directory, $target);

        return $target;
    }

    public function migrate(string $releasePath): void
    {
        $result = $this->processes->run(new ProcessInvocation(
            // --isolated: a second migrate, such as one left running by a deployer that died, waits for this one.
            arguments: ReleaseArtisan::command($this->php, $releasePath.'/apps/gateway/artisan', ['migrate', '--force', '--isolated', '--no-interaction'], ['APP_CONFIG_CACHE' => $releasePath.'/apps/gateway/bootstrap/cache/config.php'], stepLock: $this->stepLock),
            timeout: $this->migrateTimeout,
        ));

        if (! $result->succeeded()) {
            throw new GatewayReleaseException(
                step: 'migrate',
                errorCode: 'gateway.release_migrate_failed',
                message: 'The release migrations failed. The database may be partly migrated; the snapshot holds the state before.',
                status: 500,
                result: $result,
            );
        }
    }

    /** Refuses before writing when the snapshot would leave less than the floor free. */
    private function assertRoom(string $directory): void
    {
        $free = ($this->freeSpace)($directory);
        $needed = $this->snapshotBytes() + $this->minimumFreeBytes;

        if ($free !== false && $free < $needed) {
            throw new GatewayReleaseException(
                step: 'snapshot',
                errorCode: 'gateway.release_disk_low',
                message: sprintf('The backup directory has %d MiB free; a snapshot needs %d MiB plus the %d MiB floor.', (int) ($free / 1_048_576), intdiv($needed - $this->minimumFreeBytes, 1_048_576), intdiv($this->minimumFreeBytes, 1_048_576)),
            );
        }
    }

    private function prune(string $directory, string $created): void
    {
        $snapshots = array_values(array_diff(glob($directory.'/pre-*.sqlite') ?: [], [$created]));
        usort($snapshots, static fn (string $left, string $right): int => [(int) @filemtime($right), $right] <=> [(int) @filemtime($left), $left]);

        $snapshots = [$created, ...$snapshots];
        $paused = $this->pausedSnapshot();

        foreach (array_slice($snapshots, max(1, $this->keptSnapshots)) as $old) {
            if ($old !== $paused) {
                @unlink($old);
            }
        }
    }

    /** The snapshot a paused release names: the state before its migrations, which a restore needs. */
    private function pausedSnapshot(): ?string
    {
        $marker = @file_get_contents(rtrim($this->orbitHome, '/').'/gateway-release.paused');
        $decoded = is_string($marker) ? json_decode($marker, true) : null;
        $snapshot = is_array($decoded) ? ($decoded['snapshot'] ?? null) : null;

        return is_string($snapshot) ? $snapshot : null;
    }

    private function snapshotFailed(string $message, ?Throwable $previous = null): GatewayReleaseException
    {
        return new GatewayReleaseException(
            step: 'snapshot',
            errorCode: 'gateway.release_snapshot_failed',
            message: $message,
            status: 500,
            previous: $previous,
        );
    }
}

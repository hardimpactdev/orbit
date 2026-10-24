<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseDatabase;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use Illuminate\Support\Facades\DB;

/** Supplies configured database and retention intent to native release commands. */
final readonly class SqliteGatewayReleaseDatabase implements GatewayReleaseDatabase
{
    public const int KeptSnapshots = 3;

    public function __construct(
        private GatewayReleaseCommands $commands,
        private GatewayReleaseLayout $layout,
        private string $orbitHome,
        private string $stepLock,
        private ?string $connection = null,
        private int $keptSnapshots = self::KeptSnapshots,
        private int $minimumFreeBytes = GatewayReleaseBuilder::MinimumFreeBytes,
    ) {}

    public function snapshotBytes(): int
    {
        $database = DB::connection($this->connection)->getConfig('database');

        return ! is_string($database) || $database === '' || $database === ':memory:'
            ? 0 : $this->commands->databaseBytes($database);
    }

    public function snapshot(string $id): string
    {
        $id = GatewayReleaseCommit::assertId($id);
        $connection = DB::connection($this->connection);
        $database = $connection->getConfig('database');
        if ($connection->getDriverName() !== 'sqlite' || ! is_string($database) || $database === '' || $database === ':memory:') {
            throw new GatewayReleaseException('snapshot', 'gateway.release_snapshot_unavailable', 'A release snapshot requires a file-backed SQLite Gateway database.', 500);
        }

        return $this->commands->snapshot($database, $this->orbitHome, $id, max(1, $this->keptSnapshots), max(0, $this->minimumFreeBytes), $this->stepLock);
    }

    public function migrate(string $releasePath): void
    {
        $id = GatewayReleaseCommit::assertId(basename($releasePath));
        if ($this->layout->releasePath($id) !== $releasePath) {
            throw new GatewayReleaseException('migrate', 'gateway.release_layout_invalid', 'The migration target is outside the Gateway release layout.');
        }
        $this->commands->migrate($this->layout, $id, $this->stepLock);
    }
}

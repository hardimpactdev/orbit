<?php

declare(strict_types=1);

namespace App\Domain\Instances\Sqlite;

use App\Infrastructure\Instances\ProtectedSqliteSnapshotTransfer;
use Illuminate\Container\Attributes\Bind;

#[Bind(ProtectedSqliteSnapshotTransfer::class)]
interface SqliteSnapshotTransfer
{
    public function transfer(
        SqliteSeedPlacement $source,
        string $sourcePath,
        SqliteSeedPlacement $target,
        string $targetPath,
        int $expectedBytes,
        string $expectedDigest,
    ): void;
}

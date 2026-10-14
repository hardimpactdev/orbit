<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

/** The database snapshot and Laravel migrator that run before every deploy switch. */
interface GatewayReleaseDatabase
{
    /** A consistent, private copy of the Gateway database. Returns its absolute path. */
    public function snapshot(string $id): string;

    public function migrate(string $releasePath): void;

    /** The bytes a snapshot needs now, so prepare can keep room for it. */
    public function snapshotBytes(): int;
}

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The Composer and npm package cache subsystem is gone: no caller ever observed a lockfile, so the snapshot and entry tables hold
 * nothing the Gateway reads. Each drop holds when it ran before, because a run that stopped half way must not break the release on
 * SQLite. The entries go first: they point at the same Projects and Nodes as the snapshots.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['package_cache_entries', 'package_cache_snapshots'] as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
            }
        }
    }
};

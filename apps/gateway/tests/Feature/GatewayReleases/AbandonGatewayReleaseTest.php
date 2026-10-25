<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseDatabase;
use App\Domain\GatewayReleases\GatewayReleaseDrain;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Models\GatewayRelease;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\GatewayReleasePipeline;

beforeEach(function (): void {
    $this->pipeline = new GatewayReleasePipeline(adopted: true);
    $this->source = $this->pipeline->adopt();
    $this->target = $this->pipeline->fixture->commit('Refusing destructive migration');
    $this->pipeline->bindLeaves();
    mkdir($this->pipeline->home.'/annotations', 0700);
    file_put_contents($this->pipeline->home.'/annotations/retained.txt', 'retained attachment');
    $this->databasePath = $this->pipeline->home.'/gateway.sqlite';
    $copy = new PDO('sqlite:'.$this->databasePath);
    $copy->beginTransaction();
    foreach (DB::select("SELECT sql FROM sqlite_master WHERE type = 'table' AND sql IS NOT NULL AND name NOT LIKE 'sqlite_%'") as $table) {
        $copy->exec($table->sql);
    }
    foreach (DB::select("SELECT sql FROM sqlite_master WHERE type IN ('index', 'trigger') AND sql IS NOT NULL") as $object) {
        $copy->exec($object->sql);
    }
    $copy->exec('CREATE TABLE recovery_owners (id INTEGER PRIMARY KEY)');
    $copy->exec('CREATE TABLE recovery_annotations (id INTEGER PRIMARY KEY, owner_id INTEGER REFERENCES recovery_owners(id), body BLOB)');
    $copy->exec('INSERT INTO recovery_owners VALUES (3)');
    $copy->exec("INSERT INTO recovery_annotations VALUES (1, 3, X'00')");
    $copy->commit();
    $copy = null;
    chmod($this->databasePath, 0600);
    $this->oldDefault = config('database.default');
    config(['database.default' => 'abandon_test', 'database.connections.abandon_test' => ['driver' => 'sqlite', 'database' => $this->databasePath, 'foreign_key_constraints' => true]]);
    $this->pipeline->fixture->releaseCommands();
    DB::statement('PRAGMA journal_mode=WAL');
    DB::statement('PRAGMA wal_autocheckpoint=0');
    DB::statement("UPDATE recovery_annotations SET body = X'0001FF'");
    $this->drain = new class implements GatewayReleaseDrain
    {
        public bool $refuse = false;

        public int $calls = 0;

        public int $refuseAt = 0;

        public function assertHeld(string $targetSha): void
        {
            if ($this->refuse || ++$this->calls === $this->refuseAt) {
                throw new GatewayReleaseException('drain', 'gateway.release_not_drained', 'Drain changed');
            }
        }
    };
    app()->instance(GatewayReleaseDrain::class, $this->drain);
    $this->database = new class($this->pipeline->fixture->database($this->pipeline->home)) implements GatewayReleaseDatabase
    {
        public string $effect = 'none';

        public bool $fail = true;

        public function __construct(private readonly GatewayReleaseDatabase $real) {}

        public function snapshot(string $id): string
        {
            return $this->real->snapshot($id);
        }

        public function snapshotBytes(): int
        {
            return $this->real->snapshotBytes();
        }

        public function migrate(string $releasePath): void
        {
            if ($this->effect === 'data') {
                DB::statement("UPDATE recovery_annotations SET body = X'02'");
            } elseif ($this->effect === 'schema') {
                DB::statement('DROP TABLE recovery_annotations');
            }
            if ($this->fail) {
                throw new GatewayReleaseException('migrate', 'gateway.release_migrate_failed', 'No migration ledger entry was written.');
            }
        }
    };
    app()->instance(GatewayReleaseDatabase::class, $this->database);
    $this->restore = function (string $snapshot): void {
        DB::purge('abandon_test');
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($this->databasePath.$suffix)) {
                rename($this->databasePath.$suffix, $this->databasePath.'.failed'.bin2hex(random_bytes(4)).$suffix);
            }
        }
        copy($snapshot, $this->databasePath);
        chmod($this->databasePath, 0600);
    };
});

afterEach(function (): void {
    DB::purge('abandon_test');
    config(['database.default' => $this->oldDefault]);
    $this->pipeline->cleanup();
});

it('requires a complete restore before abandoning a refused stage and permits a second public stage', function (string $effect): void {
    expect(DB::selectOne('PRAGMA journal_mode')->journal_mode)->toBe('wal')
        ->and(filesize($this->databasePath.'-wal'))->toBeGreaterThan(0);
    $this->database->effect = $effect;
    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(1);
    expect(json_decode(Artisan::output(), true)['error_code'])->toBe('gateway.release_migrate_failed', Artisan::output());
    $record = GatewayRelease::query()->sole();
    $original = $record->snapshot_path;
    $arguments = ['commit' => $this->target, 'record' => (string) $record->id];
    expect(Artisan::call('gateway:release:abandon', $arguments))->toBe(1)
        ->and(json_decode(Artisan::output(), true)['error_code'])->toBe('gateway.release_restore_required')
        ->and(is_file($this->pipeline->home.'/gateway-release.paused'))->toBeTrue();
    ($this->restore)($original);
    expect(Artisan::call('gateway:release:deploy', ['commit' => $this->target]))->toBe(1);
    expect(Artisan::call('gateway:release:abandon', $arguments))->toBe(0, Artisan::output());
    $abandoned = GatewayRelease::query()->findOrFail($record->id);
    expect($abandoned->outcome)->toBe('abandoned')
        ->and($abandoned->previous_release_id)->toBe(substr($this->source, 0, 12))
        ->and($abandoned->phases['recovery']['original'])->toBe($original)
        ->and(DB::selectOne('PRAGMA integrity_check')->integrity_check)->toBe('ok')
        ->and(DB::select('PRAGMA foreign_key_check'))->toBe([])
        ->and(DB::selectOne('SELECT hex(a.body) AS body FROM recovery_annotations a JOIN recovery_owners o ON a.owner_id = o.id WHERE o.id = 3')->body)->toBe('0001FF')
        ->and($this->pipeline->automation()->enabled())->toBeFalse()
        ->and($this->pipeline->steps)->toBe([]);
    $protected = $abandoned->snapshot_path;
    $this->database->effect = 'none';
    $this->database->fail = false;
    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(0, Artisan::output())
        ->and(GatewayRelease::query()->count())->toBe(2)
        ->and(is_file($protected))->toBeTrue()
        ->and(file_get_contents($this->pipeline->home.'/annotations/retained.txt'))->toBe('retained attachment')
        ->and(GatewayRelease::query()->findOrFail($record->id)->outcome)->toBe('abandoned');
})->with(['none', 'data', 'schema']);

it('refuses altered identities and a lost drain without changing restored data or clearing the hold', function (string $fault): void {
    Artisan::call('gateway:release:stage', ['commit' => $this->target]);
    $record = GatewayRelease::query()->sole();
    ($this->restore)($record->snapshot_path);
    $arguments = ['commit' => $this->target, 'record' => (string) $record->id];
    if ($fault === 'sha') {
        $arguments['commit'] = str_repeat('a', 40);
    } elseif ($fault === 'record') {
        $arguments['record'] = (string) ($record->id + 1);
    } elseif ($fault === 'drain') {
        $this->drain->refuse = true;
    } elseif ($fault === 'source') {
        $this->pipeline->fixture->select(substr($this->target, 0, 12));
    } elseif ($fault === 'recovery') {
        $this->pipeline->recoveryAgents->refuse('gateway.verify-release-recovery', 'gateway.release_recovery_invalid');
    } elseif ($fault === 'automatic') {
        $this->pipeline->automation()->enable();
    } elseif ($fault === 'partial-restore') {
        DB::statement("UPDATE recovery_annotations SET body = X'04'");
    }
    $before = GatewayRelease::query()->findOrFail($record->id)->getAttributes();
    expect(Artisan::call('gateway:release:abandon', $arguments))->not->toBe(0)
        ->and(GatewayRelease::query()->findOrFail($record->id)->getAttributes())->toBe($before)
        ->and(is_file($this->pipeline->home.'/gateway-release.paused'))->toBeTrue()
        ->and($this->pipeline->steps)->toBe([]);
})->with(['sha', 'record', 'drain', 'source', 'recovery', 'automatic', 'partial-restore']);

it('recovers a post-migration barrier failure without treating a complete ledger as data recovery', function (): void {
    $this->database->fail = false;
    $this->database->effect = 'schema';
    $this->drain->refuseAt = 4;
    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(1);
    $record = GatewayRelease::query()->sole();
    expect($record->phases['migrate']['outcome'])->toBe('migrated')
        ->and($record->phases['drain']['outcome'])->toBe('failed');
    $arguments = ['commit' => $this->target, 'record' => (string) $record->id];
    expect(Artisan::call('gateway:release:abandon', $arguments))->toBe(1);
    ($this->restore)($record->snapshot_path);
    expect(Artisan::call('gateway:release:abandon', $arguments))->toBe(0, Artisan::output())
        ->and(DB::selectOne('SELECT hex(body) AS body FROM recovery_annotations')->body)->toBe('0001FF');
});

it('repeats only an exact completed abandonment when its marker removal was interrupted', function (): void {
    Artisan::call('gateway:release:stage', ['commit' => $this->target]);
    $record = GatewayRelease::query()->sole();
    $markerPath = $this->pipeline->home.'/gateway-release.paused';
    $marker = file_get_contents($markerPath);
    ($this->restore)($record->snapshot_path);
    $arguments = ['commit' => $this->target, 'record' => (string) $record->id];
    expect(Artisan::call('gateway:release:abandon', $arguments))->toBe(0, Artisan::output());
    file_put_contents($markerPath, $marker);
    chmod($markerPath, 0600);
    expect(Artisan::call('gateway:release:abandon', $arguments))->toBe(0, Artisan::output());
    file_put_contents($markerPath, $marker);
    chmod($markerPath, 0600);
    DB::statement("UPDATE recovery_annotations SET body = X'05'");
    expect(Artisan::call('gateway:release:abandon', $arguments))->toBe(1)
        ->and(is_file($markerPath))->toBeTrue();
});

it('reports foreign-key violations before migration and leaves source data available for repair', function (): void {
    $this->pipeline->recoveryAgents->refuse('gateway.protect-release-recovery', 'gateway.release_recovery_foreign_keys', ['violations' => [['table' => 'recovery_annotations', 'rowid' => 1, 'parent' => 'recovery_owners', 'fkid' => 0]], 'limit' => 20]);
    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(1);
    $output = json_decode(Artisan::output(), true);
    expect($output['error_code'])->toBe('gateway.release_recovery_foreign_keys')
        ->and($output['detail']['violations'][0])->toBe(['table' => 'recovery_annotations', 'rowid' => 1, 'parent' => 'recovery_owners', 'fkid' => 0])
        ->and(is_file($this->pipeline->home.'/gateway-release.paused'))->toBeFalse()
        ->and(GatewayRelease::query()->sole()->migrations_ran)->toBeFalse();
    $this->pipeline->answerRecoveryCopy();
    $this->database->fail = false;
    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(0, Artisan::output());
});

it('refuses a type-only partial restore before abandoning the staged hold', function (): void {
    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(1);
    $record = GatewayRelease::query()->sole();
    ($this->restore)($record->snapshot_path);
    $before = DB::selectOne('SELECT typeof(body) AS kind, hex(body) AS bytes FROM recovery_annotations');
    expect($before->kind)->toBe('blob');
    expect($before->bytes)->toBe('0001FF');
    DB::statement('UPDATE recovery_annotations SET body = CAST(body AS TEXT)');
    $after = DB::selectOne('SELECT typeof(body) AS kind, hex(body) AS bytes FROM recovery_annotations');
    expect($after->kind)->toBe('text');
    expect($after->bytes)->toBe('0001FF');
    $attributes = GatewayRelease::query()->findOrFail($record->id)->getAttributes();
    $code = Artisan::call('gateway:release:abandon', ['commit' => $this->target, 'record' => (string) $record->id]);
    expect($code)->toBe(1);
    expect(json_decode(Artisan::output(), true)['error_code'])->toBe('gateway.release_restore_required');
    expect(GatewayRelease::query()->findOrFail($record->id)->getAttributes())->toBe($attributes);
    expect(is_file($this->pipeline->home.'/gateway-release.paused'))->toBeTrue();
});

it('keeps the hold for type-only drift after abandonment committed before marker removal', function (string $sql): void {
    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(1);
    $record = GatewayRelease::query()->sole();
    $markerPath = $this->pipeline->home.'/gateway-release.paused';
    $marker = file_get_contents($markerPath);
    ($this->restore)($record->snapshot_path);
    $arguments = ['commit' => $this->target, 'record' => (string) $record->id];
    expect(Artisan::call('gateway:release:abandon', $arguments))->toBe(0, Artisan::output());
    file_put_contents($markerPath, $marker);
    chmod($markerPath, 0600);
    DB::statement($sql);
    $attributes = GatewayRelease::query()->findOrFail($record->id)->getAttributes();
    expect(Artisan::call('gateway:release:abandon', $arguments))->toBe(1);
    expect(json_decode(Artisan::output(), true)['error_code'])->toBe('gateway.release_restore_required');
    expect(GatewayRelease::query()->findOrFail($record->id)->getAttributes())->toBe($attributes);
    expect(file_get_contents($markerPath))->toBe($marker);
})->with([
    'ordinary row' => 'UPDATE recovery_annotations SET body = CAST(body AS TEXT)',
    'normalized completed record' => 'UPDATE gateway_releases SET message = CAST(message AS BLOB)',
]);

it('rejects changed SQLite cell identity without clearing a restored hold', function (string $before, string $after): void {
    DB::statement('CREATE TABLE recovery_cells (value)');
    DB::statement('INSERT INTO recovery_cells VALUES ('.$before.')');
    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(1);
    $record = GatewayRelease::query()->sole();
    ($this->restore)($record->snapshot_path);
    DB::statement('UPDATE recovery_cells SET value = '.$after);
    $attributes = GatewayRelease::query()->findOrFail($record->id)->getAttributes();
    expect(Artisan::call('gateway:release:abandon', ['commit' => $this->target, 'record' => (string) $record->id]))->toBe(1);
    expect(json_decode(Artisan::output(), true)['error_code'])->toBe('gateway.release_restore_required');
    expect(GatewayRelease::query()->findOrFail($record->id)->getAttributes())->toBe($attributes);
    expect(is_file($this->pipeline->home.'/gateway-release.paused'))->toBeTrue();
})->with([
    'null vs empty text' => ['NULL', "''"],
    'empty text vs empty blob' => ["''", "X''"],
    'integer vs real' => ['1', '1.0'],
    'integer vs text' => ['1', "'1'"],
    'integer beyond double precision' => ['9007199254740992', '9007199254740993'],
    'largest integers' => ['9223372036854775806', '9223372036854775807'],
    'smallest integers' => ['-9223372036854775808', '-9223372036854775807'],
    'adjacent doubles' => ['1.0', '1.0000000000000002'],
    'subnormal double' => ['0.0', '4.9406564584124654e-324'],
    'finite vs infinity' => ['1.7976931348623157e308', '1e999'],
    'text after embedded nul' => ["CAST(X'610062' AS TEXT)", "CAST(X'610063' AS TEXT)"],
    'blob bytes' => ["X'00FF'", "X'00FE'"],
]);

it('preserves real column names and intrinsic rowids while checking restored rows', function (string $sql): void {
    DB::statement('CREATE TABLE recovery_collisions ("__restore_rowid" TEXT, "_rowid_" TEXT, "rowid" TEXT, "quote""column" TEXT)');
    DB::statement("INSERT INTO recovery_collisions (oid, __restore_rowid, _rowid_, rowid) VALUES (7, 'real column', 'shadow', 'shadow')");
    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(1);
    $record = GatewayRelease::query()->sole();
    ($this->restore)($record->snapshot_path);
    DB::statement($sql);
    expect(Artisan::call('gateway:release:abandon', ['commit' => $this->target, 'record' => (string) $record->id]))->toBe(1);
    expect(json_decode(Artisan::output(), true)['error_code'])->toBe('gateway.release_restore_required');
    expect(is_file($this->pipeline->home.'/gateway-release.paused'))->toBeTrue();
})->with([
    'real alias-named column' => "UPDATE recovery_collisions SET __restore_rowid = 'changed'",
    'intrinsic rowid behind shadowed names' => 'UPDATE recovery_collisions SET oid = 8',
]);

it('accepts an exact typed restore across page layout and WAL changes', function (): void {
    DB::statement('CREATE TABLE recovery_cells (id INTEGER PRIMARY KEY, value)');
    DB::statement("INSERT INTO recovery_cells (value) VALUES (NULL), (0), (-9223372036854775808), (9223372036854775807), (1.0), (1.0000000000000002), (4.9406564584124654e-324), (1e999), (-1e999), (''), (CAST(X'6100FF' AS TEXT)), (X''), (X'0001FF'), (X'0001FF')");
    DB::statement('CREATE TABLE recovery_keyed (key TEXT PRIMARY KEY, value BLOB) WITHOUT ROWID');
    DB::statement("INSERT INTO recovery_keyed VALUES ('α', X'00FF')");
    DB::statement('CREATE TABLE recovery_collisions ("__restore_rowid" TEXT, "_RoWiD_" TEXT, "rowid" TEXT, "quote""column" TEXT)');
    DB::statement("INSERT INTO recovery_collisions VALUES ('real column', 'shadow', 'shadow', 'WITHOUT ROWID')");
    DB::statement('CREATE TABLE recovery_duplicates (value BLOB)');
    DB::statement("INSERT INTO recovery_duplicates VALUES (X'00'), (X'00')");
    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(1);
    $record = GatewayRelease::query()->sole();
    ($this->restore)($record->snapshot_path);
    $pageSize = (int) DB::selectOne('PRAGMA page_size')->page_size;
    DB::statement('PRAGMA journal_mode=DELETE');
    DB::statement('PRAGMA page_size='.($pageSize === 4096 ? 8192 : 4096));
    DB::statement('VACUUM');
    expect((int) DB::selectOne('PRAGMA page_size')->page_size)->not->toBe($pageSize);
    DB::statement('PRAGMA journal_mode=WAL');
    DB::statement('PRAGMA wal_autocheckpoint=0');
    DB::statement("UPDATE recovery_annotations SET body = X'02'");
    DB::statement("UPDATE recovery_annotations SET body = X'0001FF'");
    clearstatcache(true, $this->databasePath.'-wal');
    expect(DB::selectOne('PRAGMA journal_mode')->journal_mode)->toBe('wal');
    expect(filesize($this->databasePath.'-wal'))->toBeGreaterThan(0);
    expect(DB::selectOne('PRAGMA integrity_check')->integrity_check)->toBe('ok');
    expect(DB::select('PRAGMA foreign_key_check'))->toBe([]);
    expect(Artisan::call('gateway:release:abandon', ['commit' => $this->target, 'record' => (string) $record->id]))->toBe(0, Artisan::output());
    expect(DB::table('recovery_cells')->count())->toBe(14);
    expect(DB::selectOne('SELECT typeof(body) AS kind, hex(body) AS bytes FROM recovery_annotations'))->toMatchObject(['kind' => 'blob', 'bytes' => '0001FF']);
    expect(is_file($this->pipeline->home.'/gateway-release.paused'))->toBeFalse();
});

it('does not mistake a SQL string for a without-rowid declaration', function (): void {
    DB::statement("CREATE TABLE recovery_literal (value TEXT DEFAULT 'WITHOUT ROWID')");
    DB::statement('INSERT INTO recovery_literal (rowid) VALUES (1)');
    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(1);
    $record = GatewayRelease::query()->sole();
    ($this->restore)($record->snapshot_path);
    DB::statement('UPDATE recovery_literal SET rowid = 2');
    expect(Artisan::call('gateway:release:abandon', ['commit' => $this->target, 'record' => (string) $record->id]))->toBe(1);
    expect(json_decode(Artisan::output(), true)['error_code'])->toBe('gateway.release_restore_required');
    expect(is_file($this->pipeline->home.'/gateway-release.paused'))->toBeTrue();
});

it('refuses unverifiable intrinsic rowids rather than silently omitting them', function (): void {
    DB::statement('CREATE TABLE recovery_shadowed (rowid, _rowid_, oid)');
    DB::statement('INSERT INTO recovery_shadowed VALUES (1, 2, 3)');
    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(1);
    $record = GatewayRelease::query()->sole();
    ($this->restore)($record->snapshot_path);
    expect(Artisan::call('gateway:release:abandon', ['commit' => $this->target, 'record' => (string) $record->id]))->toBe(1);
    expect(json_decode(Artisan::output(), true)['error_code'])->toBe('gateway.release_restore_required');
    expect(is_file($this->pipeline->home.'/gateway-release.paused'))->toBeTrue();
});

it('retains duplicate row counts in the restore proof', function (): void {
    DB::statement('CREATE TABLE recovery_duplicates (value BLOB)');
    DB::statement("INSERT INTO recovery_duplicates VALUES (X'00'), (X'00')");
    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(1);
    $record = GatewayRelease::query()->sole();
    ($this->restore)($record->snapshot_path);
    DB::statement('DELETE FROM recovery_duplicates WHERE rowid = 2');
    expect(Artisan::call('gateway:release:abandon', ['commit' => $this->target, 'record' => (string) $record->id]))->toBe(1);
    expect(json_decode(Artisan::output(), true)['error_code'])->toBe('gateway.release_restore_required');
    expect(is_file($this->pipeline->home.'/gateway-release.paused'))->toBeTrue();
});

it('distinguishes adjacent real values even with reduced PHP serialization precision', function (): void {
    DB::statement('CREATE TABLE recovery_reals (value)');
    DB::statement('INSERT INTO recovery_reals VALUES (1.0)');
    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(1);
    $record = GatewayRelease::query()->sole();
    ($this->restore)($record->snapshot_path);
    DB::statement('UPDATE recovery_reals SET value = 1.0000000000000002');
    $precision = ini_set('serialize_precision', '3');
    try {
        expect(Artisan::call('gateway:release:abandon', ['commit' => $this->target, 'record' => (string) $record->id]))->toBe(1);
        expect(json_decode(Artisan::output(), true)['error_code'])->toBe('gateway.release_restore_required');
        expect(is_file($this->pipeline->home.'/gateway-release.paused'))->toBeTrue();
    } finally {
        ini_set('serialize_precision', $precision);
    }
});

it('keeps the hold when a virtual table prevents complete restore verification', function (): void {
    DB::statement('CREATE VIRTUAL TABLE recovery_search USING fts5(body)');
    DB::statement("INSERT INTO recovery_search VALUES ('retained')");
    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(1);
    $record = GatewayRelease::query()->sole();
    ($this->restore)($record->snapshot_path);
    $attributes = GatewayRelease::query()->findOrFail($record->id)->getAttributes();
    expect(Artisan::call('gateway:release:abandon', ['commit' => $this->target, 'record' => (string) $record->id]))->toBe(1);
    expect(json_decode(Artisan::output(), true)['error_code'])->toBe('gateway.release_restore_required');
    expect(GatewayRelease::query()->findOrFail($record->id)->getAttributes())->toBe($attributes);
    expect(is_file($this->pipeline->home.'/gateway-release.paused'))->toBeTrue();
});

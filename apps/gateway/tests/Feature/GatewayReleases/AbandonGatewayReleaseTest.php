<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseDatabase;
use App\Domain\GatewayReleases\GatewayReleaseDrain;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Infrastructure\GatewayReleases\SqliteGatewayReleaseDatabase;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Models\GatewayRelease;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\GatewayReleasePipeline;

pest()->group('subprocess');

beforeEach(function (): void {
    $this->pipeline = new GatewayReleasePipeline;
    $this->source = $this->pipeline->adopt();
    $this->target = $this->pipeline->fixture->commit('Refusing destructive migration');
    $this->pipeline->bindLeaves();
    mkdir($this->pipeline->home.'/annotations', 0700);
    file_put_contents($this->pipeline->home.'/annotations/retained.txt', 'retained attachment');
    $this->databasePath = $this->pipeline->home.'/gateway.sqlite';
    $copy = new PDO('sqlite:'.$this->databasePath);
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
    $copy = null;
    chmod($this->databasePath, 0600);
    $this->oldDefault = config('database.default');
    config(['database.default' => 'abandon_test', 'database.connections.abandon_test' => ['driver' => 'sqlite', 'database' => $this->databasePath, 'foreign_key_constraints' => true]]);
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
    $this->database = new class(new SqliteGatewayReleaseDatabase(new NativeProcessRunner, $this->pipeline->home, keptSnapshots: 1, minimumFreeBytes: 0)) implements GatewayReleaseDatabase
    {
        public string $effect = 'none';

        public bool $fail = true;

        public function __construct(private GatewayReleaseDatabase $real) {}

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
        unlink($this->pipeline->fixture->layout->currentPath());
        symlink($this->pipeline->fixture->layout->linkTarget(substr($this->target, 0, 12)), $this->pipeline->fixture->layout->currentPath());
    } elseif ($fault === 'recovery') {
        file_put_contents($record->phases['recovery']['path'], 'corrupt');
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
    DB::statement('PRAGMA foreign_keys=OFF');
    DB::statement('UPDATE recovery_annotations SET owner_id=99');
    DB::statement('PRAGMA foreign_keys=ON');
    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(1);
    $output = json_decode(Artisan::output(), true);
    expect($output['error_code'])->toBe('gateway.release_recovery_foreign_keys')
        ->and($output['detail']['violations'][0])->toBe(['table' => 'recovery_annotations', 'rowid' => 1, 'parent' => 'recovery_owners', 'fkid' => 0])
        ->and(is_file($this->pipeline->home.'/gateway-release.paused'))->toBeFalse()
        ->and(GatewayRelease::query()->sole()->migrations_ran)->toBeFalse();
    DB::statement('UPDATE recovery_annotations SET owner_id=3');
    $this->database->fail = false;
    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(0, Artisan::output());
});

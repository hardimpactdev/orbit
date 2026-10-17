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
    $this->databasePath = $this->pipeline->home.'/gateway.sqlite';
    $copy = new PDO('sqlite:'.$this->databasePath);
    foreach (DB::select("SELECT sql FROM sqlite_master WHERE type = 'table' AND sql IS NOT NULL AND name NOT LIKE 'sqlite_%'") as $table) {
        $copy->exec($table->sql);
    }
    $copy->exec('CREATE TABLE recovery_owners (id INTEGER PRIMARY KEY)');
    $copy->exec('CREATE TABLE recovery_annotations (id INTEGER PRIMARY KEY, owner_id INTEGER REFERENCES recovery_owners(id), body BLOB)');
    $copy->exec('INSERT INTO recovery_owners VALUES (3)');
    $copy->exec("INSERT INTO recovery_annotations VALUES (1, 3, X'0001FF')");
    $copy = null;
    chmod($this->databasePath, 0600);
    $this->oldDefault = config('database.default');
    config(['database.default' => 'abandon_test', 'database.connections.abandon_test' => ['driver' => 'sqlite', 'database' => $this->databasePath, 'foreign_key_constraints' => true]]);
    DB::statement('PRAGMA journal_mode=WAL');
    DB::statement('PRAGMA wal_autocheckpoint=0');
    $this->drain = new class implements GatewayReleaseDrain
    {
        public bool $refuse = false;
        public function assertHeld(string $targetSha): void
        {
            if ($this->refuse) {
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
        public function snapshot(string $id): string { return $this->real->snapshot($id); }
        public function snapshotBytes(): int { return $this->real->snapshotBytes(); }
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
    $this->database->effect = $effect;
    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(1);
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
        ->and(DB::selectOne('SELECT hex(body) AS body FROM recovery_annotations WHERE owner_id = 3')->body)->toBe('0001FF')
        ->and($this->pipeline->automation()->enabled())->toBeFalse()
        ->and($this->pipeline->steps)->toBe([]);
    $protected = $abandoned->snapshot_path;
    $this->database->effect = 'none';
    $this->database->fail = false;
    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(0, Artisan::output())
        ->and(GatewayRelease::query()->count())->toBe(2)
        ->and(is_file($protected))->toBeTrue()
        ->and(GatewayRelease::query()->findOrFail($record->id)->outcome)->toBe('abandoned');
})->with(['none', 'data', 'schema']);

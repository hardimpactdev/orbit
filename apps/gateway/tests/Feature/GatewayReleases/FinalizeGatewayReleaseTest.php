<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseDatabase;
use App\Domain\GatewayReleases\GatewayReleaseDrain;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Infrastructure\GatewayReleases\SqliteGatewayReleaseDatabase;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\GatewayRelease;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\GatewayReleasePipeline;

pest()->group('subprocess');

beforeEach(function (): void {
    $this->pipeline = new GatewayReleasePipeline;
    $this->source = $this->pipeline->adopt();
    $this->target = $this->pipeline->fixture->commit('Staged recovery');
    $this->pipeline->bindLeaves();
    app()->instance(GatewayReleaseDrain::class, new class implements GatewayReleaseDrain
    {
        public function assertHeld(string $targetSha): void {}
    });
    config(['database.connections.recovery_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
    $db = DB::connection('recovery_test');
    $db->statement('CREATE TABLE owners (id INTEGER PRIMARY KEY)');
    $db->statement('CREATE TABLE notes (id INTEGER PRIMARY KEY, owner_id INTEGER REFERENCES owners(id), body TEXT)');
    $db->statement('INSERT INTO owners VALUES (3)');
    $db->statement("INSERT INTO notes VALUES (1, 3, 'recoverable note and association')");
    $this->database = new SqliteGatewayReleaseDatabase(new NativeProcessRunner, $this->pipeline->home, connection: 'recovery_test', keptSnapshots: 1, minimumFreeBytes: 0);
    app()->instance(GatewayReleaseDatabase::class, new class($this->database, $this->pipeline) implements GatewayReleaseDatabase
    {
        public function __construct(private GatewayReleaseDatabase $real, private GatewayReleasePipeline $pipeline) {}

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
            $this->pipeline->migrate($releasePath);
        }
    });
    $this->status = new class implements ProcessRunner
    {
        public bool $pending = false;

        public function run(ProcessInvocation $invocation): CommandResult
        {
            expect($invocation->arguments)->toContain('migrate:status', '--pending=3');
            $artisan = $invocation->arguments[array_search('migrate:status', $invocation->arguments, true) - 1];
            expect($invocation->arguments)->toContain('APP_CONFIG_CACHE='.dirname($artisan).'/bootstrap/cache/config.php');
            $code = $this->pending ? 3 : 0;

            return new CommandResult($code, '', '', 0, false);
        }
    };
    app()->instance(ProcessRunner::class, $this->status);
    expect(Artisan::call('gateway:release:stage', ['commit' => $this->target]))->toBe(0, Artisan::output());
    $this->staged = GatewayRelease::query()->sole();
    $this->original = $this->staged->snapshot_path;
    $this->originalHash = hash_file('sha256', $this->original);
    $this->pipeline->steps = [];
});

afterEach(function (): void {
    DB::purge('recovery_test');
    $this->pipeline->cleanup();
});

it('finalizes the public staged record once and preserves verified recovery through real keep-one retention', function (): void {
    $arguments = ['commit' => $this->target, 'record' => (string) $this->staged->id];
    if (Artisan::call('gateway:release:finalize', $arguments) !== 0) {
        throw new RuntimeException(Artisan::output());
    }
    $first = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    $row = $this->staged->fresh();
    expect($row->outcome)->toBe('verified')
        ->and($row->previous_release_id)->toBe(substr($this->source, 0, 12))
        ->and($row->snapshot_path)->toStartWith($this->pipeline->home.'/recovery/')
        ->and(hash_file('sha256', $row->snapshot_path))->toBe($this->originalHash)
        ->and($row->phases['recovery']['original'])->toBe($this->original)
        ->and($this->pipeline->steps)->not->toContain('migrate')
        ->and(is_file($this->pipeline->home.'/gateway-release.paused'))->toBeFalse();
    touch($this->original, time() - 60);
    $this->database->snapshot(substr($this->target, 0, 12));
    expect(is_file($this->original))->toBeFalse()
        ->and(is_file($row->snapshot_path))->toBeTrue();
    $this->pipeline->steps = [];
    expect(Artisan::call('gateway:release:finalize', $arguments))->toBe(0)
        ->and(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR))->toBe($first)
        ->and($this->pipeline->steps)->toBe([])
        ->and(GatewayRelease::query()->count())->toBe(1)
        ->and($this->pipeline->automation()->enabled())->toBeFalse();
});

it('refuses pending migrations and wrong staged identities before runtime or recovery writes', function (): void {
    foreach ([['commit' => str_repeat('a', 40), 'record' => (string) $this->staged->id], ['commit' => $this->target, 'record' => (string) ($this->staged->id + 1)]] as $arguments) {
        expect(Artisan::call('gateway:release:finalize', $arguments))->toBe(2);
    }
    $this->status->pending = true;
    expect(Artisan::call('gateway:release:finalize', ['commit' => $this->target, 'record' => (string) $this->staged->id]))->toBe(1)
        ->and($this->pipeline->steps)->toBe([])
        ->and(is_dir($this->pipeline->home.'/recovery'))->toBeFalse()
        ->and($this->staged->fresh()->outcome)->toBe('paused');
});

it('keeps original source and protected recovery after failed finalization and refuses bypasses', function (): void {
    $this->pipeline->failVerify = $this->target;
    $arguments = ['commit' => $this->target, 'record' => (string) $this->staged->id];
    expect(Artisan::call('gateway:release:finalize', $arguments))->toBe(1)
        ->and($this->staged->fresh()->outcome)->toBe('paused')
        ->and($this->staged->fresh()->previous_release_id)->toBe(substr($this->source, 0, 12))
        ->and(Artisan::call('gateway:release:deploy', ['commit' => $this->target]))->toBe(1)
        ->and(fn () => $this->pipeline->automation()->resume())->toThrow(GatewayReleaseException::class);
    $this->pipeline->failVerify = null;
    if (Artisan::call('gateway:release:finalize', $arguments) !== 0) {
        throw new RuntimeException(Artisan::output());
    }
    file_put_contents($this->staged->fresh()->snapshot_path, 'corrupted');
    $this->pipeline->steps = [];
    expect(Artisan::call('gateway:release:finalize', $arguments))->toBe(1)
        ->and($this->pipeline->steps)->toBe([]);
});

it('refuses missing or invalid original recovery before any consumer starts', function (): void {
    file_put_contents($this->original, 'not SQLite');
    expect(Artisan::call('gateway:release:finalize', ['commit' => $this->target, 'record' => (string) $this->staged->id]))->toBe(1)
        ->and($this->pipeline->steps)->toBe([])
        ->and($this->staged->fresh()->outcome)->toBe('paused')
        ->and(is_file($this->pipeline->home.'/gateway-release.paused'))->toBeTrue();
});

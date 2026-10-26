<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseDatabase;
use App\Domain\GatewayReleases\GatewayReleaseDrain;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseSmoke;
use App\Models\GatewayRelease;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\GatewayReleasePipeline;

beforeEach(function (): void {
    $this->pipeline = new GatewayReleasePipeline(adopted: true);
    $this->source = $this->pipeline->adopt();
    $this->target = $this->pipeline->fixture->commit('Staged recovery');
    $this->pipeline->bindLeaves();
    app()->instance(GatewayReleaseDrain::class, new class implements GatewayReleaseDrain
    {
        public function assertHeld(string $targetSha): void {}
    });
    touch($this->pipeline->home.'/recovery-test.sqlite');
    config(['database.connections.recovery_test' => ['driver' => 'sqlite', 'database' => $this->pipeline->home.'/recovery-test.sqlite', 'foreign_key_constraints' => true]]);
    $db = DB::connection('recovery_test');
    $db->statement('CREATE TABLE owners (id INTEGER PRIMARY KEY)');
    $db->statement('CREATE TABLE notes (id INTEGER PRIMARY KEY, owner_id INTEGER REFERENCES owners(id), body TEXT)');
    $db->statement('INSERT INTO owners VALUES (3)');
    $db->statement("INSERT INTO notes VALUES (1, 3, 'recoverable note and association')");
    $this->database = $this->pipeline->fixture->database($this->pipeline->home, 'recovery_test');
    app()->instance(GatewayReleaseDatabase::class, new readonly class($this->database, $this->pipeline) implements GatewayReleaseDatabase
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
    // Simulate retirement of the ordinary snapshot; the protected recovery copy remains independent.
    unlink($this->original);
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
    $this->pipeline->fixture->agents->refuse('gateway.check-release-migrations', 'gateway.release_schema_pending');
    expect(Artisan::call('gateway:release:finalize', ['commit' => $this->target, 'record' => (string) $this->staged->id]))->toBe(1)
        ->and($this->pipeline->steps)->toBe([])
        ->and(hash_file('sha256', $this->staged->phases['recovery']['path']))->toBe($this->originalHash)
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
    $this->pipeline->recoveryAgents->refuse('gateway.verify-release-recovery', 'gateway.release_recovery_invalid');
    $this->pipeline->steps = [];
    expect(Artisan::call('gateway:release:finalize', $arguments))->toBe(1)
        ->and($this->pipeline->steps)->toBe([]);
});

it('refuses missing or invalid original recovery before any consumer starts', function (): void {
    $this->pipeline->recoveryAgents->refuse('gateway.verify-release-recovery', 'gateway.release_recovery_invalid');
    expect(Artisan::call('gateway:release:finalize', ['commit' => $this->target, 'record' => (string) $this->staged->id]))->toBe(1)
        ->and($this->pipeline->steps)->toBe([])
        ->and($this->staged->fresh()->outcome)->toBe('paused')
        ->and(is_file($this->pipeline->home.'/gateway-release.paused'))->toBeTrue();
});

it('retains the hold if protected recovery changes after runtime handoff', function (): void {
    $smoke = new readonly class($this->pipeline->recoveryAgents) implements GatewayReleaseSmoke
    {
        public function __construct(private FakeAgentCommandRunner $agents) {}

        public function run(string $id, string $sha, ?DateTimeImmutable $since = null, array $skip = []): array
        {
            $this->agents->refuse('gateway.verify-release-recovery', 'gateway.release_recovery_invalid');

            return ['outcome' => 'passed', 'report' => null];
        }
    };
    app()->instance(GatewayReleaseSmoke::class, $smoke);
    expect(Artisan::call('gateway:release:finalize', ['commit' => $this->target, 'record' => (string) $this->staged->id]))->toBe(1)
        ->and(json_decode(Artisan::output(), true)['error_code'])->toBe('gateway.release_recovery_invalid')
        ->and($this->pipeline->steps)->toContain('handoff:'.substr($this->target, 0, 12))
        ->and($this->staged->fresh()->outcome)->toBe('paused')
        ->and(is_file($this->pipeline->home.'/gateway-release.paused'))->toBeTrue();
});

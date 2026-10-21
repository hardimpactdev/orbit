<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseDrain;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Infrastructure\GatewayReleases\GatewayReleaseRetry;
use App\Models\GatewayRelease;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\GatewayReleasePipeline;

beforeEach(function (): void {
    $this->pipeline = new GatewayReleasePipeline;
});

afterEach(function (): void {
    $this->pipeline->cleanup();
});

it('stages the exact release without starting any consumer or claiming verification', function (): void {
    $source = $this->pipeline->adopt();
    $target = $this->pipeline->fixture->commit('Held schema change');
    $drain = new RecordingStageDrain;

    $result = $this->pipeline->deployer($drain)->stage($target);

    expect($result->outcome)->toBe('paused')
        ->and($result->succeeded())->toBeFalse()
        ->and($result->trigger)->toBe('stage')
        ->and($this->pipeline->automation()->pause()['reason'])->toBe('held')
        ->and($this->pipeline->alerts)->toBe([])
        ->and($result->errorCode)->toBe('gateway.release_held')
        ->and($result->previousId)->toBe(substr($source, 0, 12))
        ->and($result->migrationsRan)->toBeTrue()
        ->and($this->pipeline->steps)->toBe(['migrate'])
        ->and($this->pipeline->snapshots)->toBe(1)
        ->and($drain->targets)->toBe(array_fill(0, 4, $target))
        ->and($this->pipeline->fixture->layout->currentReleaseId())->toBe(substr($target, 0, 12))
        ->and(GatewayRelease::query()->sole()->outcome)->toBe('paused')
        ->and(is_file($this->pipeline->home.'/gateway-release.paused'))->toBeTrue();
});

it('requires explicit finalization instead of ordinary deploy or automatic resume', function (): void {
    $this->pipeline->adopt();
    $target = $this->pipeline->fixture->commit('Held schema change');
    $this->pipeline->deployer(new RecordingStageDrain)->stage($target);
    expect(fn () => $this->pipeline->deployer()->execute($target))->toThrow(GatewayReleaseException::class, 'Finalize the exact staged')
        ->and(fn () => $this->pipeline->automation()->resume())->toThrow(GatewayReleaseException::class, 'Finalize the exact staged')
        ->and(fn () => $this->pipeline->rollback()->execute(substr($target, 0, 12)))->toThrow(GatewayReleaseException::class, 'Finalize the exact staged')
        ->and($this->pipeline->snapshots)->toBe(1);
});

it('refuses the current source before snapshot or migration', function (): void {
    $source = $this->pipeline->adopt();
    expect(fn () => $this->pipeline->deployer(new RecordingStageDrain)->stage($source))->toThrow(GatewayReleaseException::class, 'already current')
        ->and($this->pipeline->steps)->toBe([])
        ->and($this->pipeline->snapshots)->toBe(0)
        ->and(GatewayRelease::query()->count())->toBe(0);
});

it('refuses a missing observer and abbreviated target before snapshot or migration', function (): void {
    $this->pipeline->adopt();
    $target = $this->pipeline->fixture->commit('Held');

    expect(fn () => $this->pipeline->deployer()->stage($target))->toThrow(GatewayReleaseException::class, 'No drain observer')
        ->and(fn () => $this->pipeline->deployer(new RecordingStageDrain)->stage(substr($target, 0, 12)))->toThrow(GatewayReleaseException::class, 'full target SHA')
        ->and($this->pipeline->snapshots)->toBe(0)
        ->and($this->pipeline->steps)->toBe([])
        ->and(GatewayRelease::query()->count())->toBe(0);
});

it('rechecks the barrier at every destructive boundary and never releases a failed hold', function (int $failure, int $snapshots, array $steps, bool $paused): void {
    $source = $this->pipeline->adopt();
    $target = $this->pipeline->fixture->commit('Held');
    $drain = new RecordingStageDrain($failure);

    expect(fn () => $this->pipeline->deployer($drain)->stage($target))->toThrow(GatewayReleaseException::class, 'Drain changed')
        ->and($this->pipeline->snapshots)->toBe($snapshots)
        ->and($this->pipeline->steps)->toBe($steps)
        ->and($this->pipeline->fixture->layout->currentReleaseId())->toBe(substr($source, 0, 12))
        ->and(is_file($this->pipeline->home.'/gateway-release.paused'))->toBe($paused);
    if ($paused) {
        expect(fn () => $this->pipeline->deployer()->execute($target))->toThrow(GatewayReleaseException::class, 'Finalize the exact staged')
            ->and(fn () => $this->pipeline->rollback()->execute(substr($source, 0, 12)))->toThrow(GatewayReleaseException::class, 'Finalize the exact staged')
            ->and(fn () => $this->pipeline->automation()->resume())->toThrow(GatewayReleaseException::class, 'Finalize the exact staged')
            ->and(GatewayRelease::query()->sole()->previous_release_id)->toBe(substr($source, 0, 12));
    }
})->with([
    'initial refusal' => [1, 0, [], false],
    'before snapshot' => [2, 0, [], false],
    'before migration' => [3, 1, [], false],
    'after migration before switch' => [4, 1, ['migrate'], true],
]);

it('retains authority after a public migration failure and interrupted process record', function (bool $interrupted): void {
    $source = $this->pipeline->adopt();
    $target = $this->pipeline->fixture->commit('Failed stage migration');
    $this->pipeline->bindLeaves();
    app()->instance(GatewayReleaseDrain::class, new RecordingStageDrain);
    $this->pipeline->failMigrate = true;
    expect(Artisan::call('gateway:release:stage', ['commit' => $target]))->toBe(1);
    $record = GatewayRelease::query()->sole();
    expect($record->previous_release_id)->toBe(substr($source, 0, 12))
        ->and($record->phases['hold']['record'])->toBe($record->id)
        ->and(is_file($this->pipeline->home.'/gateway-release.paused'))->toBeTrue();
    if ($interrupted) {
        $record->forceFill(['outcome' => 'interrupted', 'error_code' => 'gateway.release_interrupted'])->save();
    }
    $this->pipeline->failMigrate = false;
    $this->pipeline->steps = [];
    expect(Artisan::call('gateway:release:deploy', ['commit' => $target]))->toBe(1)
        ->and(fn () => $this->pipeline->automation()->resume())->toThrow(GatewayReleaseException::class)
        ->and(Artisan::call('gateway:release:rollback', ['release' => substr($source, 0, 12)]))->toBe(1)
        ->and($this->pipeline->steps)->toBe([])
        ->and($this->pipeline->snapshots)->toBe(1)
        ->and(GatewayRelease::query()->count())->toBe(1);
})->with([false, true]);

final class RecordingStageDrain implements GatewayReleaseDrain
{
    /** @var list<string> */
    public array $targets = [];

    public function __construct(private readonly int $failure = 0) {}

    public function assertHeld(string $targetSha): void
    {
        $this->targets[] = $targetSha;
        if (count($this->targets) === $this->failure) {
            throw new GatewayReleaseException('drain', 'gateway.release_not_drained', 'Drain changed');
        }
    }
}

it('keeps stage drain failures out of the normal deployment retry budget', function (): void {
    $this->pipeline->adopt();
    $target = $this->pipeline->fixture->commit('Drain retries');
    for ($attempt = 0; $attempt < 3; $attempt++) {
        expect(fn () => $this->pipeline->deployer(new RecordingStageDrain(2))->stage($target))->toThrow(GatewayReleaseException::class);
    }
    expect(GatewayRelease::query()->pluck('trigger')->all())->toBe(['stage', 'stage', 'stage'])
        ->and((new GatewayReleaseRetry)->retryable('gateway.release_not_drained', $target))->toBeTrue();
});

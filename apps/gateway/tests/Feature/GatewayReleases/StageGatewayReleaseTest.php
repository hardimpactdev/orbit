<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseDrain;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Models\GatewayRelease;
use Tests\Support\GatewayReleasePipeline;

pest()->group('subprocess');

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

it('finalizes the same staged SHA while retaining its pre-migration snapshot', function (): void {
    $this->pipeline->adopt();
    $target = $this->pipeline->fixture->commit('Held schema change');
    $staged = $this->pipeline->deployer(new RecordingStageDrain)->stage($target);
    mkdir(dirname($staged->snapshotPath), 0700, true);
    file_put_contents($staged->snapshotPath, 'preserved pre-migration snapshot');
    $this->pipeline->steps = [];

    $finished = $this->pipeline->deployer()->execute($target);

    expect($finished->outcome)->toBe('verified')
        ->and($finished->sha)->toBe($target)
        ->and($finished->snapshotPath)->toBe($staged->snapshotPath)
        ->and($finished->phases['snapshot']['path'])->not->toBe($staged->snapshotPath)
        ->and($this->pipeline->snapshots)->toBe(2)
        ->and($this->pipeline->steps)->toBe([
            'migrate', 'handoff:'.substr($target, 0, 12), 'verify',
            'schedule:'.substr($target, 0, 12), 'web:publish', 'smoke',
        ])
        ->and(GatewayRelease::query()->latest('id')->first()->outcome)->toBe('verified');
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
})->with([
    'initial refusal' => [1, 0, [], false],
    'before snapshot' => [2, 0, [], false],
    'before migration' => [3, 1, [], false],
    'after migration before switch' => [4, 1, ['migrate'], true],
]);

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

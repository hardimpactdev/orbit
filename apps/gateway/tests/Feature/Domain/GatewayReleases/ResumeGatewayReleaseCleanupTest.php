<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayReleaseCleanup;
use App\Infrastructure\GatewayReleases\GatewayCleanupHandoff;
use Illuminate\Support\Facades\Artisan;

/** One cleanup whose generation rotates when the scheduler restarts, and whose report has the given differences. */
function releaseCleanup(int $differences): GatewayReleaseCleanup
{
    return new class($differences) implements GatewayReleaseCleanup
    {
        public string $generation = 'old';

        public bool $resumed = false;

        public function __construct(private readonly int $differences) {}

        public function configured(): bool
        {
            return true;
        }

        public function status(): array
        {
            return ['cleanup_state' => $this->resumed ? 'running' : 'paused', 'cleanup_generation' => $this->generation];
        }

        public function reconcile(): array
        {
            return ['report_id' => 'report-1', 'report_state' => 'complete', 'difference_count' => $this->differences];
        }

        public function resume(string $reportId): array
        {
            $this->resumed = $reportId === 'report-1';

            return ['cleanup_state' => 'running'];
        }
    };
}

function runReleaseCleanup(GatewayReleaseCleanup $cleanup, array $options = []): array
{
    app()->instance(GatewayCleanupHandoff::class, new GatewayCleanupHandoff([$cleanup], 1, static function (int $microseconds): void {}));
    $exit = Artisan::call('gateway:release:cleanup', $options);

    return [$exit, json_decode(Artisan::output(), true)];
}

it('prints the generations before the restart and resumes under the new one after it', function (): void {
    $cleanup = releaseCleanup(0);
    [$readExit, $read] = runReleaseCleanup($cleanup);
    $cleanup->generation = 'new';
    [$exit, $result] = runReleaseCleanup($cleanup, ['--before' => json_encode($read['generations'])]);

    expect($readExit)->toBe(0)
        ->and($read)->toBe(['generations' => ['old']])
        ->and($exit)->toBe(0)
        ->and($result)->toMatchArray(['outcome' => 'resumed', 'paused' => false])
        ->and($cleanup->resumed)->toBeTrue();
});

it('exits 1 and leaves the cleanup paused when its report has differences or the generation never changed', function (int $differences, string $generation, string $code): void {
    $cleanup = releaseCleanup($differences);
    $cleanup->generation = $generation;
    [$exit, $result] = runReleaseCleanup($cleanup, ['--before' => '["old"]']);

    expect($exit)->toBe(1)
        ->and($result)->toMatchArray(['outcome' => 'paused', 'paused' => true, 'error_code' => $code])
        ->and($cleanup->resumed)->toBeFalse();
})->with([
    'differences' => [1, 'new', 'gateway.release_cleanup_unresolved'],
    'no new generation' => [0, 'old', 'gateway.release_cleanup_generation_unchanged'],
]);

it('refuses generations that are not a JSON list', function (): void {
    [$exit, $result] = runReleaseCleanup(releaseCleanup(0), ['--before' => '{"a":1}']);

    expect($exit)->toBe(2)
        ->and($result['error_code'])->toBe('gateway.release_cleanup_generations_invalid');
});

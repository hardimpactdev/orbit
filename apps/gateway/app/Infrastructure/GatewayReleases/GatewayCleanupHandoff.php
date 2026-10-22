<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseCleanup;
use Closure;
use Illuminate\Container\Attributes\Tag;

/**
 * Resumes each extension's release cleanup after the runtime handoff ({@see GatewayReleaseCleanup}). A scheduler start
 * or an FPM reload rotates a cleanup's generation and pauses it, so after a scheduler restart the handoff waits for the
 * new generation, reconciles, and resumes with that report. A report that cannot authorize resume leaves the cleanup
 * paused; the release reports that and alerts instead of failing, because the release itself is healthy.
 *
 * @phpstan-type CleanupResult array{outcome: string, paused: bool, error_code?: string, report_id?: string, difference_count?: int}
 */
final readonly class GatewayCleanupHandoff
{
    /** @var Closure(int): void */
    private Closure $sleep;

    /** @var Closure(): float */
    private Closure $clock;

    /**
     * @param  iterable<int, GatewayReleaseCleanup>  $cleanups
     * @param  (Closure(int): void)|null  $sleep  microseconds
     * @param  (Closure(): float)|null  $clock  monotonic nanoseconds
     */
    public function __construct(
        #[Tag(GatewayReleaseCleanup::class)] private iterable $cleanups,
        private int $generationWaitSeconds = 30,
        ?Closure $sleep = null,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => hrtime(true);
        $this->sleep = $sleep ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    /**
     * Each cleanup's generation before the scheduler stops, so the handoff can tell when the new scheduler has started.
     *
     * @return list<string|null>
     */
    public function generation(): array
    {
        return array_map(
            static fn (GatewayReleaseCleanup $cleanup): ?string => $cleanup->configured() ? $cleanup->status()['cleanup_generation'] : null,
            [...$this->cleanups],
        );
    }

    /**
     * One outcome for every cleanup: the first paused cleanup, otherwise `resumed`, `running`, or `skipped`.
     *
     * @param  list<string|null>  $generationsBefore
     * @return CleanupResult
     */
    public function resume(array $generationsBefore, bool $schedulerRestarted): array
    {
        $results = [];
        foreach ($this->cleanups as $index => $cleanup) {
            $results[] = $this->resumeOne($cleanup, $generationsBefore[$index] ?? null, $schedulerRestarted);
        }

        foreach ($results as $result) {
            if ($result['paused']) {
                return $result;
            }
        }
        foreach (['resumed', 'running'] as $outcome) {
            foreach ($results as $result) {
                if ($result['outcome'] === $outcome) {
                    return $result;
                }
            }
        }

        return ['outcome' => 'skipped', 'paused' => false];
    }

    /** @return CleanupResult */
    private function resumeOne(GatewayReleaseCleanup $cleanup, ?string $generationBefore, bool $schedulerRestarted): array
    {
        if (! $cleanup->configured()) {
            return ['outcome' => 'skipped', 'paused' => false];
        }

        $status = $schedulerRestarted ? $this->awaitNewGeneration($cleanup, $generationBefore) : $cleanup->status();

        if ($status === null) {
            return ['outcome' => 'paused', 'paused' => true, 'error_code' => 'gateway.release_cleanup_generation_unchanged'];
        }

        if ($status['cleanup_state'] === 'running') {
            return ['outcome' => 'running', 'paused' => false];
        }

        $report = $cleanup->reconcile();
        $reportId = $report['report_id'] ?? null;

        if (isset($report['error_code']) || $reportId === null || ($report['report_state'] ?? null) !== 'complete' || ($report['difference_count'] ?? 1) !== 0) {
            return array_filter([
                'outcome' => 'paused',
                'paused' => true,
                'error_code' => $report['error_code'] ?? 'gateway.release_cleanup_unresolved',
                'report_id' => $reportId,
                'difference_count' => $report['difference_count'] ?? null,
            ], static fn (mixed $value): bool => $value !== null);
        }

        $resumed = $cleanup->resume($reportId);

        if (isset($resumed['error_code']) || ($resumed['cleanup_state'] ?? null) !== 'running') {
            return ['outcome' => 'paused', 'paused' => true, 'error_code' => $resumed['error_code'] ?? 'gateway.release_cleanup_unresolved', 'report_id' => $reportId];
        }

        return ['outcome' => 'resumed', 'paused' => false, 'report_id' => $reportId];
    }

    /** @return array{cleanup_state: string, cleanup_generation: string|null}|null */
    private function awaitNewGeneration(GatewayReleaseCleanup $cleanup, ?string $before): ?array
    {
        $deadline = ($this->clock)() + $this->generationWaitSeconds * 1_000_000_000;

        do {
            $status = $cleanup->status();

            if ($status['cleanup_generation'] !== null && $status['cleanup_generation'] !== $before) {
                return $status;
            }

            ($this->sleep)(500_000);
        } while (($this->clock)() < $deadline);

        return null;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

/**
 * A plug-in point: an extension's cleanup that a scheduler restart pauses ([Extensions](/reference/extensions#plug-in-points)).
 * After the release handoff restarts the scheduler, it waits for the new generation, reconciles, and resumes each
 * tagged cleanup. Project Documents contributes its restore-time cleanup gate.
 *
 * @phpstan-type CleanupStatus array{cleanup_state: string, cleanup_generation: string|null, error_code?: string}
 * @phpstan-type CleanupReport array{report_id?: string, report_state?: string, difference_count?: int, error_code?: string}
 */
interface GatewayReleaseCleanup
{
    /** Whether the cleanup has anything to clean up, such as configured storage. */
    public function configured(): bool;

    /** @return CleanupStatus */
    public function status(): array;

    /** @return CleanupReport */
    public function reconcile(): array;

    /** @return array{cleanup_state?: string, error_code?: string} */
    public function resume(string $reportId): array;
}

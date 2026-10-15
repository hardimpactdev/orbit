<?php

declare(strict_types=1);

namespace Orbit\Extensions\Documents\Infrastructure;

use App\Domain\GatewayReleases\GatewayReleaseCleanup;
use Orbit\Extensions\Documents\Actions\DocumentCleanupControlAction;
use Orbit\Extensions\Documents\Actions\RecoverDocumentCleanupAction;
use Orbit\Extensions\Documents\Models\ProjectDocumentStorage;

/** The cleanup gate through the same actions as the `project-documents:cleanup:*` commands. */
final readonly class DocumentsReleaseCleanup implements GatewayReleaseCleanup
{
    public function __construct(
        private DocumentCleanupControlAction $control,
        private RecoverDocumentCleanupAction $recovery,
    ) {}

    public function configured(): bool
    {
        $storage = ProjectDocumentStorage::query()->find(1);

        return $storage instanceof ProjectDocumentStorage && $storage->endpoint !== null;
    }

    public function status(): array
    {
        $status = $this->control->handle(false, false);
        $result = [
            'cleanup_state' => is_string($status['cleanup_state'] ?? null) ? $status['cleanup_state'] : 'paused',
            'cleanup_generation' => is_string($status['cleanup_generation'] ?? null) ? $status['cleanup_generation'] : null,
        ];
        if (is_string($status['error_code'] ?? null)) {
            $result['error_code'] = $status['error_code'];
        }

        return $result;
    }

    public function reconcile(): array
    {
        $report = $this->recovery->reconcile();

        return array_filter([
            'report_id' => is_string($report['report_id'] ?? null) ? $report['report_id'] : null,
            'report_state' => is_string($report['report_state'] ?? null) ? $report['report_state'] : null,
            'difference_count' => is_int($report['difference_count'] ?? null) ? $report['difference_count'] : null,
            'error_code' => is_string($report['error_code'] ?? null) ? $report['error_code'] : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    public function resume(string $reportId): array
    {
        $result = $this->recovery->resume($reportId);

        return array_filter([
            'cleanup_state' => is_string($result['cleanup_state'] ?? null) ? $result['cleanup_state'] : null,
            'error_code' => is_string($result['error_code'] ?? null) ? $result['error_code'] : null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}

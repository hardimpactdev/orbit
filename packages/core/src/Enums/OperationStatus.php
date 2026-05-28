<?php

declare(strict_types=1);

namespace Orbit\Core\Enums;

/**
 * Status for any single operation attempt. Used by:
 *  - operation tokens (queued/running/succeeded/failed/expired/rejected at mint or verify time),
 *  - operation_runs rows (the per-attempt UUID row created by OperationRunRecorder per D5/D14).
 *
 * There is exactly one status enum across the protocol; do not introduce a parallel
 * OperationRunStatus.
 */
enum OperationStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Expired = 'expired';
    case Rejected = 'rejected';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Queued, self::Running => false,
            self::Succeeded, self::Failed, self::Expired, self::Rejected => true,
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;
use App\Data\Doctor\DoctorRestoreProbe;

/**
 * Probe/apply callables for one node-scoped restore convergence run.
 */
final readonly class DoctorRestoreCallbacks
{
    /**
     * @param  callable(): DoctorRestoreProbe  $probe
     * @param  callable(list<DoctorIssue>): list<array<string, mixed>>  $apply
     * @param  callable(DoctorIssue): bool  $isRestorable
     */
    public function __construct(
        public mixed $probe,
        public mixed $apply,
        public mixed $isRestorable,
    ) {}
}

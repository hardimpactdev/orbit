<?php

declare(strict_types=1);

namespace App\Services\Doctor;

/**
 * Probe/apply callables for one node-scoped restore convergence run.
 */
final readonly class DoctorRestoreCallbacks
{
    /**
     * @param  callable(): array{issues?: list<array<string, mixed>>}  $probe
     * @param  callable(list<array<string, mixed>>): list<array<string, mixed>>  $apply
     * @param  callable(array<string, mixed>): bool  $isRestorable
     */
    public function __construct(
        public mixed $probe,
        public mixed $apply,
        public mixed $isRestorable,
    ) {}
}

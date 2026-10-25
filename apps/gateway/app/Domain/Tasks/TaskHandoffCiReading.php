<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\RequiredCheckState;

/** One bounded hosted-CI observation; it chooses no scheduler action. */
final readonly class TaskHandoffCiReading
{
    /** @param list<array<string, mixed>> $runs
     * @param  list<array<string, mixed>>  $suites
     */
    public function __construct(
        public RequiredCheckState $state,
        public ?string $fault = null,
        public string $output = '',
        public array $runs = [],
        public array $suites = [],
    ) {}
}

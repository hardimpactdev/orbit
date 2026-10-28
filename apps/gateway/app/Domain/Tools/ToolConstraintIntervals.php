<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use Composer\Semver\Intervals;
use Composer\Semver\VersionParser;

/** Compile Gateway-validated Composer constraints without a second range parser in Swamp. */
final readonly class ToolConstraintIntervals
{
    /** @return list<array{start: string, start_operator: string, end: string, end_operator: string}>|null */
    public function for(?string $constraint): ?array
    {
        if ($constraint === null) {
            return null;
        }
        $intervals = Intervals::get(new VersionParser()->parseConstraints($constraint));

        return array_values(array_map(static fn ($interval): array => [
            'start' => $interval->getStart()->getVersion(),
            'start_operator' => $interval->getStart()->getOperator(),
            'end' => $interval->getEnd()->getVersion(),
            'end_operator' => $interval->getEnd()->getOperator(),
        ], $intervals['numeric']));
    }
}

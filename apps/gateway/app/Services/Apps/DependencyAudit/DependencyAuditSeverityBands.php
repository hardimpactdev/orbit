<?php

declare(strict_types=1);

namespace App\Services\Apps\DependencyAudit;

final readonly class DependencyAuditSeverityBands
{
    /**
     * @param  array<string, int>  $severityCounts
     * @return array{danger: int, warning: int}
     */
    public function fromSeverityCounts(array $severityCounts): array
    {
        $danger = 0;
        $warning = 0;

        foreach ($severityCounts as $severity => $count) {
            $normalized = mb_strtolower($severity);

            if (in_array($normalized, ['critical', 'high'], true)) {
                $danger += $count;

                continue;
            }

            if (in_array($normalized, ['moderate', 'medium', 'low', 'unknown', 'info'], true)) {
                $warning += $count;

                continue;
            }

            if ($normalized === '' || $normalized === 'null') {
                $warning += $count;
            }
        }

        return [
            'danger' => $danger,
            'warning' => $warning,
        ];
    }

    public function classifyComposerSeverity(?string $severity): string
    {
        $normalized = mb_strtolower((string) $severity);

        if ($normalized === '' || $normalized === 'null') {
            return 'unknown';
        }

        return $normalized;
    }
}

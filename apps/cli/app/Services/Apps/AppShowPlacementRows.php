<?php

declare(strict_types=1);

namespace App\Services\Apps;

/** @mago-expect lint:cyclomatic-complexity */
final class AppShowPlacementRows
{
    /**
     * @param  array<string, mixed>  $app
     * @param  array<string, mixed>  $details
     * @return list<array{string, string, string, string, string}>
     */
    public function forApp(array $app, array $details): array
    {
        $instances = is_array($details['instances'] ?? null)
            ? array_values(array_filter($details['instances'], is_array(...)))
            : [];
        $rows = [];
        $dependencyLabel = $this->dependencyLabel($app);

        foreach ($instances as $instance) {
            $node = $this->valueLabel($instance['node'] ?? null);

            $rows[] = [
                $this->valueLabel($instance['name'] ?? null),
                $this->valueLabel($instance['driver'] ?? null),
                $node,
                $this->valueLabel($instance['url'] ?? null),
                $dependencyLabel,
            ];

            $workspaces = is_array($instance['workspaces'] ?? null)
                ? array_values(array_filter($instance['workspaces'], is_array(...)))
                : [];
            $lastIndex = count($workspaces) - 1;

            foreach ($workspaces as $index => $workspace) {
                $prefix = $index === $lastIndex ? '└─ ' : '├─ ';

                $rows[] = [
                    $prefix.$this->valueLabel($workspace['name'] ?? null),
                    'workspace',
                    $node,
                    $this->valueLabel($workspace['url'] ?? null),
                    '—',
                ];
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $app
     */
    public function dependencyLabel(array $app): string
    {
        $status = $this->valueLabel($app['dependency_audit_status'] ?? null);

        if ($status !== 'findings') {
            return $status;
        }

        $danger = $this->nonNegativeCount($app['dependency_danger_count'] ?? null);
        $warning = $this->nonNegativeCount($app['dependency_warning_count'] ?? null);

        return "findings ({$danger} danger, {$warning} warning)";
    }

    private function nonNegativeCount(mixed $value): int
    {
        return is_int($value) && $value >= 0 ? $value : 0;
    }

    private function valueLabel(mixed $value): string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : '—';
    }
}

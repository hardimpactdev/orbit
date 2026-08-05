<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

/**
 * Canonical app.instance selectors from a gateway instance inventory payload.
 */
final readonly class ApplicationLogInstanceInventory
{
    /**
     * @param  array<string, mixed>  $data  success.data from GET /api/instances
     * @return list<string>
     */
    public function selectors(array $data): array
    {
        $instances = is_array($data['instances'] ?? null) ? $data['instances'] : [];
        $selectors = [];

        foreach ($instances as $instance) {
            if (! is_array($instance)) {
                continue;
            }

            $app = $instance['app'] ?? null;
            $name = $instance['name'] ?? null;

            if (! is_string($app) || trim($app) === '' || ! is_string($name) || trim($name) === '') {
                continue;
            }

            $selectors[] = trim($app).'.'.trim($name);
        }

        return array_values(array_unique($selectors));
    }

    /**
     * @param  list<string>  $selectors
     */
    public function contains(string $selector, array $selectors): bool
    {
        return in_array($selector, $selectors, true);
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

/**
 * Row parsing for GET /api/instances inventory payloads.
 */
final readonly class ApplicationLogInstanceInventoryRows
{
    /**
     * @param  array<string, mixed>  $data
     * @return list<array{selector: string, path: ?string}>
     */
    public function all(array $data): array
    {
        $instances = is_array($data['instances'] ?? null) ? $data['instances'] : [];
        $rows = [];

        foreach ($instances as $instance) {
            if (! is_array($instance)) {
                continue;
            }

            $app = $instance['app'] ?? null;
            $name = $instance['name'] ?? null;

            if (! is_string($app) || trim($app) === '' || ! is_string($name) || trim($name) === '') {
                continue;
            }

            $path = $instance['path'] ?? null;
            $ownedPath = is_string($path) && trim($path) !== '' && str_starts_with(trim($path), '/')
                ? trim($path)
                : null;

            $rows[] = [
                'selector' => trim($app).'.'.trim($name),
                'path' => $ownedPath,
            ];
        }

        return $rows;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

/**
 * Canonical app.instance selectors and owned paths from GET /api/instances.
 */
final readonly class ApplicationLogInstanceInventory
{
    public function __construct(
        private ApplicationLogInstanceInventoryRows $rows = new ApplicationLogInstanceInventoryRows,
    ) {}

    /**
     * @param  array<string, mixed>  $data  success.data from GET /api/instances
     * @return list<array{selector: string, path: string}>
     */
    public function pathEntries(array $data): array
    {
        $entries = [];

        foreach ($this->rows->all($data) as $row) {
            if ($row['path'] === null) {
                continue;
            }

            $entries[] = [
                'selector' => $row['selector'],
                'path' => $row['path'],
            ];
        }

        return $entries;
    }
}

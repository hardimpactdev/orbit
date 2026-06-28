<?php

declare(strict_types=1);

namespace App\Commands\Solo;

final class SoloResponseDataNormalizer
{
    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    public function successData(array $response): array
    {
        $success = $response['success'] ?? null;

        if (! is_array($success)) {
            return [];
        }

        return $this->stringKeyedArray($success['data'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function arrayRows(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $rows = [];

        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }

            $rows[] = $this->stringKeyedArray($row);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function stringKeyedArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];

        foreach (array_keys($value) as $key) {
            if (! is_string($key)) {
                continue;
            }

            $result[$key] = $value[$key];
        }

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Apps;

final readonly class LocalAppSourcePathProbe
{
    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function probe(mixed $path): array
    {
        $path = $this->path($path);

        return [
            'data' => [
                'path' => $path,
                'exists' => is_dir($path),
            ],
            'meta' => [],
        ];
    }

    private function path(mixed $value): string
    {
        if (is_string($value) && $value !== '' && str_starts_with($value, '/') && ! str_contains($value, "\0")) {
            return $value;
        }

        throw new LocalAppSourcePathProbeFailure(
            errorCode: 'validation_failed',
            message: 'App source path must be an absolute path.',
            meta: ['field' => 'path'],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Services\Apps;

final readonly class LocalAppSourcePathProbe
{
    /**
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function probe(mixed $path, mixed $boundary = null): array
    {
        $path = $this->path($path);
        $boundary = $boundary === null ? null : $this->path($boundary);
        $resolvedPath = realpath($path);
        $exists = is_string($resolvedPath) && is_dir($resolvedPath);

        $data = [
            'path' => $path,
            'exists' => $exists,
        ];

        if ($boundary === null) {
            return [
                'data' => $data,
                'meta' => [],
            ];
        }

        $resolvedBoundary = realpath($boundary);
        $withinBoundary =
            $exists && is_string($resolvedBoundary) && $this->isSameOrChild($resolvedPath, $resolvedBoundary);

        return [
            'data' => [
                ...$data,
                'resolved_path' => $exists ? $resolvedPath : null,
                'within_boundary' => $withinBoundary,
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

    private function isSameOrChild(string $path, string $boundary): bool
    {
        if ($boundary === '/') {
            return str_starts_with($path, '/');
        }

        $boundary = rtrim($boundary, characters: '/');

        return $path === $boundary || str_starts_with($path, "{$boundary}/");
    }
}

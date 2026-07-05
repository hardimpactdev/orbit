<?php

declare(strict_types=1);

namespace App\Services\Apps;

final readonly class LocalAppWorkerReadinessInputValidator
{
    public function path(mixed $value): string
    {
        if (is_string($value) && $value !== '' && str_starts_with($value, '/') && ! str_contains($value, "\0")) {
            return rtrim(string: $value, characters: '/');
        }

        throw new LocalAppWorkerReadinessProbeFailure(
            errorCode: 'validation_failed',
            message: 'App source path must be an absolute path.',
            meta: ['field' => 'path'],
        );
    }

    public function workerFile(mixed $value): string
    {
        if (! is_string($value) || $value === '' || str_starts_with($value, '/') || str_contains($value, "\0")) {
            throw new LocalAppWorkerReadinessProbeFailure(
                errorCode: 'validation_failed',
                message: 'Worker file must be a relative path.',
                meta: ['field' => 'workerFile'],
            );
        }

        if (preg_match('/\A(?!.*(?:\A|\/)\.{1,2}(?:\/|\z))[A-Za-z0-9._-]+(?:\/[A-Za-z0-9._-]+)*\z/', $value) === 1) {
            return $value;
        }

        throw new LocalAppWorkerReadinessProbeFailure(
            errorCode: 'validation_failed',
            message: 'Worker file must be a safe relative path.',
            meta: ['field' => 'workerFile'],
        );
    }
}

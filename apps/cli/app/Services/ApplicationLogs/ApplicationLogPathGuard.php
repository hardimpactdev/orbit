<?php

declare(strict_types=1);

namespace App\Services\ApplicationLogs;

final readonly class ApplicationLogPathGuard
{
    public function assertWithinAuthorizedRoot(LocalApplicationLogPayload $input): void
    {
        $expectedSuffix = '/'.LocalApplicationLogPayload::LogicalPath;

        if (! str_ends_with($input->absolutePath, $expectedSuffix)) {
            throw new LocalApplicationLogFailure(
                errorCode: 'application_log.unsafe_path',
                message: 'Application log path must be storage/logs/laravel.log under the authorized root.',
                meta: ['path' => LocalApplicationLogPayload::LogicalPath],
            );
        }

        $rootReal = realpath($input->authorizedRoot) ?: $input->authorizedRoot;
        $parent = dirname($input->absolutePath);
        $parentReal = is_dir($parent) ? (realpath($parent) ?: $parent) : $parent;

        $rootPrefix = rtrim($rootReal, characters: '/').'/';
        $candidate = rtrim($parentReal, characters: '/').'/';

        if ($candidate !== $rootPrefix && ! str_starts_with($candidate, $rootPrefix)) {
            throw new LocalApplicationLogFailure(
                errorCode: 'application_log.unsafe_path',
                message: 'Application log path escapes the authorized root.',
                meta: ['path' => LocalApplicationLogPayload::LogicalPath],
            );
        }

        if (! is_link($input->absolutePath)) {
            return;
        }

        $resolved = realpath($input->absolutePath);

        if ($resolved === false || ! str_starts_with($resolved, $rootPrefix)) {
            throw new LocalApplicationLogFailure(
                errorCode: 'application_log.unsafe_path',
                message: 'Application log path escapes the authorized root.',
                meta: ['path' => LocalApplicationLogPayload::LogicalPath],
            );
        }
    }
}

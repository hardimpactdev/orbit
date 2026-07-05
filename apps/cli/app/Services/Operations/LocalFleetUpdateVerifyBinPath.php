<?php

declare(strict_types=1);

namespace App\Services\Operations;

final class LocalFleetUpdateVerifyBinPath
{
    public static function fromPayload(mixed $binPath): string
    {
        if ($binPath === null) {
            return 'orbit';
        }

        if (! is_string($binPath)) {
            throw self::invalid();
        }

        $binPath = trim($binPath);

        if ($binPath === '' || str_contains($binPath, "\0") || basename($binPath) !== 'orbit') {
            throw self::invalid();
        }

        return $binPath;
    }

    private static function invalid(): LocalFleetUpdateVerifyFailure
    {
        return new LocalFleetUpdateVerifyFailure(
            errorCode: 'validation_failed',
            message: 'Fleet update verification binary path is invalid.',
            meta: ['field' => 'bin_path'],
        );
    }
}

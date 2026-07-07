<?php

declare(strict_types=1);

namespace App\Services\Operations;

final class LocalFleetUpdateVerifyBinPath
{
    public static function fromPayload(mixed $binPath, string $binaryName = 'orbit'): string
    {
        if ($binPath === null) {
            return $binaryName;
        }

        if (! is_string($binPath)) {
            throw self::invalid();
        }

        $binPath = trim($binPath);

        if ($binPath === '' || str_contains($binPath, "\0") || basename($binPath) !== $binaryName) {
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

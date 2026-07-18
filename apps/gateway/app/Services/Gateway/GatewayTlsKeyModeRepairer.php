<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use Illuminate\Support\Facades\File;
use RuntimeException;

final class GatewayTlsKeyModeRepairer
{
    public function repair(string $configRoot): void
    {
        $certificateDirectories = ["{$configRoot}/certs"];
        $hostPathPrefix = $this->hostPathPrefix();

        if ($hostPathPrefix !== null) {
            $certificateDirectories[] = $hostPathPrefix.'/etc/orbit/certs';
        }

        foreach ($certificateDirectories as $certificates) {
            foreach (File::glob("{$certificates}/*.key") ?: [] as $privateKey) {
                if (! is_string($privateKey) || ! File::isFile($privateKey)) {
                    continue;
                }

                File::chmod($privateKey, 0o600);
            }
        }
    }

    private function hostPathPrefix(): ?string
    {
        $hostPathPrefix = getenv('ORBIT_HOST_PATH_PREFIX');

        if (! is_string($hostPathPrefix) || trim($hostPathPrefix) === '') {
            return null;
        }

        $hostPathPrefix = rtrim(trim($hostPathPrefix), '/');

        if (
            $hostPathPrefix === ''
            || ! str_starts_with($hostPathPrefix, '/')
            || str_contains($hostPathPrefix, "\0")
            || array_any(
                explode('/', $hostPathPrefix),
                static fn (string $segment): bool => $segment === '.'
                || $segment === '..',
            )
        ) {
            throw new RuntimeException('Gateway host path prefix is invalid.');
        }

        return $hostPathPrefix;
    }
}

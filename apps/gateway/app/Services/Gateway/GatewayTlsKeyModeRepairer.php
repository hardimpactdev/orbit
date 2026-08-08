<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use Illuminate\Support\Facades\File;

final class GatewayTlsKeyModeRepairer
{
    public function repair(string $configRoot): void
    {
        $certificateDirectories = ["{$configRoot}/certs"];
        $hostPathPrefix = GatewayHostPathPrefix::resolve();

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
}

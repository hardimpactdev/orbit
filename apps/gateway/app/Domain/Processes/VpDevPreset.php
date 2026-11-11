<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Domain\Instances\InstanceHostIntent;

final readonly class VpDevPreset
{
    public const string NAME = 'vp-dev';

    public const string RestartPolicy = 'on-failure';

    /** @return list<string> */
    public static function command(?int $containerPort = null): array
    {
        if ($containerPort !== null) {
            return [InstanceHostIntent::RuntimeFolder.'/current/bin/vp', 'dev', '--host=0.0.0.0', '--port='.$containerPort, '--strictPort', '--base=/__orbit/vite/'];
        }

        return ['/usr/local/bin/vp', 'dev', '--host=127.0.0.1', '--port=${ORBIT_DEV_SERVER_PORT}', '--strictPort', '--base=/__orbit/vite/'];
    }
}

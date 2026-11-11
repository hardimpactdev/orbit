<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Domain\Instances\InstanceHostIntent;

final readonly class VpDevPreset
{
    public const string NAME = 'vp-dev';

    public const string RestartPolicy = 'on-failure';

    public const int ContainerPort = 5173;

    /** @return list<string> */
    public static function command(?int $containerPort = null): array
    {
        return $containerPort === null
            ? self::argv('/usr/local/bin/vp', '127.0.0.1', '$'.'{ORBIT_DEV_SERVER_PORT}')
            : self::argv(InstanceHostIntent::RuntimeFolder.'/current/bin/vp', '0.0.0.0', (string) $containerPort);
    }

    /** @return list<string> */
    public static function definitionCommand(): array
    {
        return self::argv('vp', '0.0.0.0', '$'.'{ORBIT_DEV_SERVER_PORT}');
    }

    /** @return list<string> */
    private static function argv(string $executable, string $host, string $port): array
    {
        return [$executable, 'dev', '--host='.$host, '--port='.$port, '--strictPort', '--base=/__orbit/vite/'];
    }
}

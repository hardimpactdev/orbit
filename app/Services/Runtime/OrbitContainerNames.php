<?php

declare(strict_types=1);

namespace App\Services\Runtime;

class OrbitContainerNames
{
    public function runtime(): string
    {
        $container = getenv('ORBIT_RUNTIME_CONTAINER');

        if (is_string($container) && $container !== '') {
            return $container;
        }

        return 'orbit-runtime';
    }

    public function caddy(): string
    {
        return 'orbit-caddy';
    }

    public function network(): string
    {
        return 'orbit-network';
    }
}

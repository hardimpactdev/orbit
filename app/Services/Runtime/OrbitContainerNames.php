<?php

declare(strict_types=1);

namespace App\Services\Runtime;

class OrbitContainerNames
{
    public function runtime(): string
    {
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

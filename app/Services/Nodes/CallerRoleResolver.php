<?php

declare(strict_types=1);

namespace App\Services\Nodes;

class CallerRoleResolver
{
    public function resolve(): string
    {
        return (bool) config('orbit.is_gateway', false) ? 'gateway' : 'control';
    }
}

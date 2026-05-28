<?php

declare(strict_types=1);

namespace App\Services\WireGuard;

interface ResolvesGatewayAddress
{
    public function resolve(): ?string;
}

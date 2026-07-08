<?php

declare(strict_types=1);

namespace App\Services;

interface NodeTransportAwareGatewayStreamClient
{
    public function withNodeTransportPreference(?string $preference): GatewayProgressStreamClient;
}

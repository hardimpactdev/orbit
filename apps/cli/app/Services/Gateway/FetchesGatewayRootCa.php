<?php

declare(strict_types=1);

namespace App\Services\Gateway;

interface FetchesGatewayRootCa
{
    public function handle(string $gatewayIp): RootCaFetchResult;
}

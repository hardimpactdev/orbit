<?php

declare(strict_types=1);

namespace App\Contracts;

use Orbit\Sdk\Laravel\GatewayApiException;

interface UpdateAllGatewayStream
{
    /**
     * @param  callable(string, array<string, mixed>): void  $onEvent
     */
    public function run(callable $onEvent): int|GatewayApiException;
}

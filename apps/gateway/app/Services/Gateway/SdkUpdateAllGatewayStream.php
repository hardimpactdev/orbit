<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Contracts\UpdateAllGatewayStream;
use Orbit\Sdk\Laravel\GatewayApiException;
use Orbit\Sdk\Laravel\UpdateAllGatewayStreamClient;

final readonly class SdkUpdateAllGatewayStream implements UpdateAllGatewayStream
{
    public function __construct(
        private UpdateAllGatewayStreamClient $client,
    ) {}

    /**
     * @param  callable(string, array<string, mixed>): void  $onEvent
     */
    public function run(callable $onEvent): int|GatewayApiException
    {
        return $this->client->run($onEvent);
    }
}

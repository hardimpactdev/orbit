<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel;

use Saloon\Http\Response;

abstract class GatewayStreamRequest extends GatewayRequest
{
    #[\Override]
    public function hasRequestFailed(Response $response): ?bool
    {
        return $response->serverError() || $response->clientError();
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultConfig(): array
    {
        return [
            'stream' => true,
            'timeout' => 0,
            'read_timeout' => 0,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Operations;

use Orbit\Sdk\Laravel\GatewayStreamRequest;
use Saloon\Enums\Method;

final class UpdateAllStreamRequest extends GatewayStreamRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/api/update/all';
    }

    /**
     * @return array<string, mixed>
     */
    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'text/event-stream',
        ];
    }
}

<?php

declare(strict_types=1);

namespace Orbit\Sdk\Laravel\Requests\Operations;

use Orbit\Sdk\Laravel\GatewayRequest;
use Orbit\Sdk\Laravel\Responses\Operations\UpdateAllResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class UpdateAllRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/api/update/all';
    }

    public function createDtoFromResponse(Response $response): UpdateAllResponse
    {
        $data = $this->unwrapData($response);
        $meta = $this->unwrapMeta($response);

        $updates = $data['updates'] ?? [];
        $summary = $meta['summary'] ?? [];

        return new UpdateAllResponse(
            updates: $this->listOfStringKeyedArrays($updates),
            summary: $this->stringKeyedArray($summary),
        );
    }
}

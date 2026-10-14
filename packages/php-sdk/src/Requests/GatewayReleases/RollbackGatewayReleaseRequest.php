<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\GatewayReleases;

use InvalidArgumentException;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\GatewayReleases\GatewayReleaseResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

/** Queues a code rollback to a retained release. The Gateway answers 202 with its record. */
final class RollbackGatewayReleaseRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $release,
    ) {
        if (preg_match('/\A[0-9a-f]{12}\z/D', $release) !== 1) {
            throw new InvalidArgumentException('A release id is the first 12 hex digits of its commit.');
        }
    }

    public function resolveEndpoint(): string
    {
        return '/api/v1/gateway/releases/'.$this->release.'/rollback';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): GatewayReleaseResponse
    {
        return GatewayReleaseResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}

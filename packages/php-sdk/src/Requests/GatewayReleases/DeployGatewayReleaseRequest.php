<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\GatewayReleases;

use InvalidArgumentException;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\GatewayReleases\GatewayReleaseResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

/** Starts a manual deploy of one commit as the `gateway.release` Swamp run. The Gateway answers 202 with the record. */
final class DeployGatewayReleaseRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(public readonly string $commit)
    {
        if (preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1) {
            throw new InvalidArgumentException('Name the commit by its full 40-character SHA.');
        }
    }

    public function resolveEndpoint(): string
    {
        return '/api/v1/gateway/releases';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): GatewayReleaseResponse
    {
        return GatewayReleaseResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array{commit: string} */
    protected function defaultBody(): array
    {
        return ['commit' => $this->commit];
    }
}

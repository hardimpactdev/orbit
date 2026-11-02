<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\GitHub;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\GitHub\GitHubAppResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

/** The Gateway reads the App's installations from GitHub now and answers like show. */
final class SyncGitHubAppRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/api/v1/github/app/sync';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): GitHubAppResponse
    {
        return GitHubAppResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}

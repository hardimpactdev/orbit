<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Proof;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Proof\ProofPoolResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

/** A record-only change of the warm proof copy pool; the Gateway answers the pool's state. */
abstract class ProofPoolRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::POST;

    abstract protected function action(): string;

    public function resolveEndpoint(): string
    {
        return "/api/v1/proof/pool/{$this->action()}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ProofPoolResponse
    {
        return ProofPoolResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}

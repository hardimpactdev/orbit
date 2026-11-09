<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Proof;

use InvalidArgumentException;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

/** Starts proof.verify for one command on the task VM of the caller; the Gateway answers HTTP 202 with the queued operation. */
final class VerifyProofRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $command,
        private readonly string $idempotencyKey,
        public readonly ?int $group = null,
    ) {
        if (preg_match('/\A[a-z][a-z0-9_]*(?::[a-z][a-z0-9_-]*)+\z/D', $command) !== 1) {
            throw new InvalidArgumentException('Name the command as family:action, for example firewall:allow.');
        }
    }

    public function resolveEndpoint(): string
    {
        return '/api/v1/proof/verify';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): OperationResponse
    {
        return OperationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return ['Idempotency-Key' => $this->idempotencyKey];
    }

    /** @return array{command: string, group?: int} */
    protected function defaultBody(): array
    {
        return ['command' => $this->command, ...$this->group === null ? [] : ['group' => $this->group]];
    }
}

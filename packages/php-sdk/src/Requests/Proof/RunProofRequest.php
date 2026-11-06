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

/** Starts proof.run for one commit; the Gateway selects the cases and answers HTTP 202 with the queued operation. */
final class RunProofRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        public readonly string $commit,
        private readonly bool $all,
        private readonly bool $reportOnly,
        private readonly string $idempotencyKey,
    ) {
        if (preg_match('/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/D', $commit) !== 1) {
            throw new InvalidArgumentException('Name the commit by its full 40 or 64 character lowercase SHA.');
        }
    }

    public function resolveEndpoint(): string
    {
        return '/api/v1/proof/run';
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

    /** @return array{commit: string, all?: true, report_only?: true} */
    protected function defaultBody(): array
    {
        return ['commit' => $this->commit, ...($this->all ? ['all' => true] : []), ...($this->reportOnly ? ['report_only' => true] : [])];
    }
}

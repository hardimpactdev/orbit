<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tools;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class AdoptToolRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    /** @param array<string, mixed>|null $repository */
    public function __construct(
        private readonly int $nodeId,
        #[\SensitiveParameter]
        private readonly string $manager,
        #[\SensitiveParameter]
        private readonly string $package,
        #[\SensitiveParameter]
        private readonly ?string $versionConstraint = null,
        private readonly ?string $idempotencyKey = null,
        #[\SensitiveParameter]
        private readonly ?array $repository = null,
    ) {}

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return $this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey];
    }

    public function resolveEndpoint(): string
    {
        return '/api/v1/tools/adopt';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): OperationResponse
    {
        return OperationResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array{node_id: int, manager: string, package: string, version_constraint?: string, repository?: array<string, mixed>} */
    protected function defaultBody(): array
    {
        return array_filter(
            [
                'node_id' => $this->nodeId,
                'manager' => $this->manager,
                'package' => $this->package,
                'version_constraint' => $this->versionConstraint,
                'repository' => $this->repository,
            ],
            static fn (mixed $value): bool => $value !== null,
        );
    }
}

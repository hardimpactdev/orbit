<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Instances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

/**
 * Admits a development Instance creation Operation.
 * New production placement uses {@see CloneInstanceRequest}.
 */
final class CreateInstanceRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $projectId,
        private readonly int $nodeId,
        private readonly string $name,
        private readonly ?string $root = null,
        private readonly ?string $domain = null,
        private readonly ?string $branch = null,
        private readonly ?string $databaseServer = null,
        private readonly ?string $idempotencyKey = null,
    ) {}

    /** @return array<string, string> */
    protected function defaultHeaders(): array
    {
        return $this->idempotencyKey === null ? [] : ['Idempotency-Key' => $this->idempotencyKey];
    }

    public function resolveEndpoint(): string
    {
        return '/api/v1/instances';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): OperationResponse
    {
        return OperationResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    /** @return array<string, bool|int|string> */
    protected function defaultBody(): array
    {
        $body = [
            'project_id' => $this->projectId,
            'node_id' => $this->nodeId,
            'name' => $this->name,
        ];

        if ($this->root !== null) {
            $body['root'] = $this->root;
        }

        if ($this->domain !== null) {
            $body['domain'] = $this->domain;
        }

        if ($this->branch !== null) {
            $body['branch'] = $this->branch;
        }

        if ($this->databaseServer !== null) {
            $body['database_server'] = $this->databaseServer;
        }

        return $body;
    }
}

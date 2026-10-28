<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Instances;

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Dependencies\DependencyResultsResponse;
use Orbit\Sdk\Support\GatewayRequestId;
use Orbit\Sdk\Support\JsonObjectKeys;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

final class ShowInstanceDependenciesRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(private readonly int $instanceId, private readonly ?string $source = null, private readonly ?int $packagesPage = null, private readonly ?int $findingsPage = null) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->instanceId}/dependencies";
    }

    protected function defaultQuery(): array
    {
        return array_filter(['source' => $this->source, 'packages_page' => $this->packagesPage, 'findings_page' => $this->findingsPage], static fn (mixed $value): bool => $value !== null);
    }

    public function hasRequestFailed(#[SensitiveParameter] Response $response): ?bool
    {
        if (strlen($response->body()) > 2_097_152) {
            throw new GatewayApiException('Gateway response exceeds the dependency result limit.', requestId: GatewayRequestId::fromTransport($response->header('X-Orbit-Request-Id')));
        }
        try {
            json_decode($response->body(), depth: 16, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new GatewayApiException('Gateway response contains invalid dependency results.', requestId: GatewayRequestId::fromTransport($response->header('X-Orbit-Request-Id')));
        }

        return parent::hasRequestFailed($response);
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): DependencyResultsResponse
    {
        $requestId = $this->successRequestId($response);
        $headerId = $this->successHeaderRequestId($response);
        if ($requestId === '' || ($headerId !== '' && $headerId !== $requestId)) {
            throw new GatewayApiException('Gateway response contains invalid dependency results.', requestId: $headerId === '' ? null : $headerId);
        }
        try {
            if (! JsonObjectKeys::areUnique($response->body())) {
                throw new GatewayApiException('Invalid dependency results.');
            }
            $dto = DependencyResultsResponse::fromArray($this->unwrapData($response));
            if ($dto->instanceId !== $this->instanceId) {
                throw new GatewayApiException('Invalid dependency target.');
            }

            return new DependencyResultsResponse($dto->projectId, $dto->instanceId, $dto->sourceRef, $dto->instanceCommit, $dto->availability, $dto->readAt, $dto->generatedAt, $dto->sourceCommit, $dto->lastAttempt, $dto->sources, $dto->report, $dto->batchPr, $requestId);
        } catch (GatewayApiException) {
            throw new GatewayApiException('Gateway response contains invalid dependency results.', requestId: $requestId);
        }
    }
}

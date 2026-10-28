<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Deployments;

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use SensitiveParameter;

final readonly class AutomaticDeployReceiptResponse
{
    public function __construct(
        public string $requestKey,
        public bool $found,
        public ?OperationResponse $operation,
        public ?InstanceDeploymentResponse $deployment,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[SensitiveParameter] array $data, string $requestId): self
    {
        if (! is_string($data['request_key'] ?? null) || ! is_bool($data['found'] ?? null)) {
            throw new GatewayApiException('Gateway response contains an invalid automatic receipt.', requestId: $requestId);
        }

        return new self(
            $data['request_key'], $data['found'],
            is_array($data['operation'] ?? null) ? OperationResponse::fromGatewayData(self::stringKeyed($data['operation']), $requestId) : null,
            is_array($data['deployment'] ?? null) ? InstanceDeploymentResponse::fromGatewayData(self::stringKeyed($data['deployment']), $requestId) : null,
            $requestId,
        );
    }

    /** @param array<mixed, mixed> $value
     * @return array<string, mixed>
     */
    private static function stringKeyed(#[SensitiveParameter] array $value): array
    {
        $record = [];
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $record[$key] = $item;
            }
        }

        return $record;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'request_key' => $this->requestKey, 'found' => $this->found,
            'operation' => $this->operation?->toArray(), 'deployment' => $this->deployment?->toArray(),
            'request_id' => $this->requestId,
        ];
    }
}

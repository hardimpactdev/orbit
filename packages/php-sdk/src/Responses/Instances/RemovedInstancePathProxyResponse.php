<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Instances;

use InvalidArgumentException;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class RemovedInstancePathProxyResponse
{
    public function __construct(public int $instanceId, public string $requestId) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[SensitiveParameter] array $data, #[SensitiveParameter] string $requestId): self
    {
        if (! is_int($data['instance_id'] ?? null) || $data['instance_id'] < 1) {
            throw new InvalidArgumentException('Invalid removed path proxy identifier.');
        }

        return new self($data['instance_id'], GatewayRequestId::fromTransport($requestId) ?? '');
    }

    /** @return array{instance_id: int, request_id: string} */
    public function toArray(): array
    {
        return ['instance_id' => $this->instanceId, 'request_id' => $this->requestId];
    }
}

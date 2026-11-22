<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Conn;

use InvalidArgumentException;

/** The result of `DELETE /api/v1/conn/environments/{environment}` (`conn:environment:unregister`). */
final readonly class UnregisteredConnEnvironmentResponse
{
    public function __construct(
        public string $environmentId,
        public bool $removed,
        public string $requestId,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        $environmentId = $data['environment_id'] ?? null;
        $removed = $data['removed'] ?? null;

        if (! is_string($environmentId) || $environmentId === '' || ! is_bool($removed)) {
            throw new InvalidArgumentException('Gateway response contains invalid Conn environment unregister data.');
        }

        return new self($environmentId, $removed, $requestId);
    }
}

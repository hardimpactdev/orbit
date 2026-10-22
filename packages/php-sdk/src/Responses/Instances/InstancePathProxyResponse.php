<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Instances;

use InvalidArgumentException;
use Orbit\Sdk\Support\GatewayOrigin;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class InstancePathProxyResponse
{
    public function __construct(
        public int $instanceId,
        public int $routeId,
        public int $processId,
        public int $port,
        public string $prefix,
        public string $endpoint,
        public bool $enabled,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[SensitiveParameter] array $data, #[SensitiveParameter] string $requestId): self
    {
        foreach (['instance_id', 'route_id', 'process_id', 'port'] as $field) {
            if (! is_int($data[$field] ?? null) || $data[$field] < 1) {
                throw new InvalidArgumentException('Invalid path proxy response identifier.');
            }
        }
        if ($data['port'] > 65535 || ! is_string($data['prefix'] ?? null) || strlen($data['prefix']) > 64
            || ! is_string($data['endpoint'] ?? null) || strlen($data['endpoint']) > 512
            || preg_match('/\A\/[a-z][a-z0-9-]{0,62}\z/D', $data['prefix']) !== 1
            || preg_match('/[\x00-\x20\x7f]/', $data['endpoint']) === 1
            || ! str_ends_with($data['endpoint'], $data['prefix'])
            || GatewayOrigin::fromTransport(substr($data['endpoint'], 0, -strlen($data['prefix']))) === null || ! is_bool($data['enabled'] ?? null)) {
            throw new InvalidArgumentException('Invalid path proxy response.');
        }

        return new self($data['instance_id'], $data['route_id'], $data['process_id'], $data['port'], $data['prefix'], $data['endpoint'],
            $data['enabled'], GatewayRequestId::fromTransport($requestId) ?? '');
    }

    /** @return array{instance_id: int, route_id: int, process_id: int, port: int, prefix: string, endpoint: string, enabled: bool, request_id: string} */
    public function toArray(): array
    {
        return ['instance_id' => $this->instanceId, 'route_id' => $this->routeId, 'process_id' => $this->processId,
            'port' => $this->port, 'prefix' => $this->prefix, 'endpoint' => $this->endpoint, 'enabled' => $this->enabled, 'request_id' => $this->requestId];
    }
}

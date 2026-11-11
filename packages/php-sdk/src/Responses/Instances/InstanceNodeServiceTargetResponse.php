<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Instances;

use InvalidArgumentException;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class InstanceNodeServiceTargetResponse
{
    public function __construct(
        public int $id,
        public int $instanceId,
        public int $nodeId,
        public int $processId,
        public string $protocol,
        public int $port,
        public string $hostEnvKey,
        public string $portEnvKey,
        public bool $changed,
        public ?string $reasonCode,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[SensitiveParameter] array $data, #[SensitiveParameter] string $requestId): self
    {
        foreach (['id', 'instance_id', 'node_id', 'process_id', 'port'] as $key) {
            if (! is_int($data[$key] ?? null) || $data[$key] < 1 || ($key === 'port' && $data[$key] > 65535)) {
                throw new InvalidArgumentException('Invalid Node service target identifier or port.');
            }
        }
        foreach (['host_env_key', 'port_env_key'] as $key) {
            if (! is_string($data[$key] ?? null) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,254}\z/D', $data[$key]) !== 1) {
                throw new InvalidArgumentException('Invalid Node service target environment key.');
            }
        }
        if (($data['protocol'] ?? null) !== 'tcp' || ! is_bool($data['changed'] ?? null)) {
            throw new InvalidArgumentException('Invalid Node service target protocol or change result.');
        }
        $reasonCode = $data['reason_code'] ?? null;
        if ($reasonCode !== null && $reasonCode !== 'instance.node_service_target_unchanged') {
            throw new InvalidArgumentException('Invalid Node service target reason code.');
        }

        return new self($data['id'], $data['instance_id'], $data['node_id'], $data['process_id'], $data['protocol'], $data['port'],
            $data['host_env_key'], $data['port_env_key'], $data['changed'], $reasonCode, GatewayRequestId::fromTransport($requestId) ?? '');
    }

    /** @return array<string, bool|int|string|null> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'instance_id' => $this->instanceId, 'node_id' => $this->nodeId, 'process_id' => $this->processId, 'protocol' => $this->protocol,
            'port' => $this->port, 'host_env_key' => $this->hostEnvKey, 'port_env_key' => $this->portEnvKey,
            'changed' => $this->changed, 'reason_code' => $this->reasonCode, 'request_id' => $this->requestId];
    }
}

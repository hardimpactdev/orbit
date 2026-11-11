<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Instances;

use InvalidArgumentException;
use SensitiveParameter;

/** @phpstan-type NodeServiceTargetRecord array{id: int, instance_id: int, node_id: int, process_id: int, protocol: string, port: int, host_env_key: string, port_env_key: string} */
final readonly class InstanceNodeServiceTargetRecordResponse
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
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[SensitiveParameter] array $data): self
    {
        foreach (['id', 'instance_id', 'node_id', 'process_id', 'port'] as $key) {
            if (! is_int($data[$key] ?? null) || $data[$key] < 1 || ($key === 'port' && $data[$key] > 65535)) {
                throw new InvalidArgumentException('Invalid Node service target record identity or port.');
            }
        }
        foreach (['protocol', 'host_env_key', 'port_env_key'] as $key) {
            if (! is_string($data[$key] ?? null)) {
                throw new InvalidArgumentException('Invalid Node service target record field.');
            }
        }

        return new self($data['id'], $data['instance_id'], $data['node_id'], $data['process_id'],
            $data['protocol'], $data['port'], $data['host_env_key'], $data['port_env_key']);
    }

    /** @return NodeServiceTargetRecord */
    public function toArray(): array
    {
        return ['id' => $this->id, 'instance_id' => $this->instanceId, 'node_id' => $this->nodeId, 'process_id' => $this->processId,
            'protocol' => $this->protocol, 'port' => $this->port, 'host_env_key' => $this->hostEnvKey, 'port_env_key' => $this->portEnvKey];
    }
}

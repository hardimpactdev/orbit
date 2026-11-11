<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Instances;

use Orbit\Sdk\Responses\Nodes\NodeIdentityResponse;
use SensitiveParameter;

/**
 * @phpstan-type AttachedTargetRecord array{kind: string, driver: string, id: int, name: string, node: array{id: int, name: string}|null, host: string|null, port: int|null, database: string|null, username: string|null, prefix: string, server: array{id: int, name: string}|null}
 */
final readonly class InstanceAttachedTargetResponse
{
    /** @param array{id: int, name: string}|null $server */
    public function __construct(
        public string $kind,
        public string $driver,
        public int $id,
        public string $name,
        public ?NodeIdentityResponse $node,
        public ?string $host,
        public ?int $port,
        public ?string $database,
        public ?string $username,
        public string $prefix,
        public ?array $server,
    ) {}

    public static function tryFromGatewayData(#[SensitiveParameter] mixed $data): ?self
    {
        if (! is_array($data)
            || ! is_string($data['kind'] ?? null)
            || ! is_string($data['driver'] ?? null)
            || ! is_int($data['id'] ?? null)
            || $data['id'] < 1
            || ! is_string($data['name'] ?? null)
            || ! is_string($data['prefix'] ?? null)) {
            return null;
        }

        $server = $data['server'] ?? null;

        return new self(
            kind: $data['kind'],
            driver: $data['driver'],
            id: $data['id'],
            name: $data['name'],
            node: NodeIdentityResponse::tryFromGatewayData($data['node'] ?? null),
            host: is_string($data['host'] ?? null) ? $data['host'] : null,
            port: is_int($data['port'] ?? null) ? $data['port'] : null,
            database: is_string($data['database'] ?? null) ? $data['database'] : null,
            username: is_string($data['username'] ?? null) ? $data['username'] : null,
            prefix: $data['prefix'],
            server: is_array($server) && is_int($server['id'] ?? null) && is_string($server['name'] ?? null)
                ? ['id' => $server['id'], 'name' => $server['name']] : null,
        );
    }

    /** @return AttachedTargetRecord */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'driver' => $this->driver,
            'id' => $this->id,
            'name' => $this->name,
            'node' => $this->node?->toArray(),
            'host' => $this->host,
            'port' => $this->port,
            'database' => $this->database,
            'username' => $this->username,
            'prefix' => $this->prefix,
            'server' => $this->server,
        ];
    }
}

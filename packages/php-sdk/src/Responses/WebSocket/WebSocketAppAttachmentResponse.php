<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\WebSocket;

use InvalidArgumentException;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

/** Names the stored environment keys an attachment wrote or removed. It never carries their values. */
final readonly class WebSocketAppAttachmentResponse
{
    private const int MAX_KEY_COUNT = 1_024;

    private const string NAME_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D';

    private const string KEY_PATTERN = '/\A[A-Za-z_][A-Za-z0-9_]{0,254}\z/D';

    /**
     * @param  list<string>  $keys
     */
    public function __construct(
        public int $instanceId,
        public string $name,
        public array $keys,
        public string $operation,
        public bool $changed,
        public int $keyCount,
        public string $requestId,
    ) {
        if ($instanceId < 1) {
            throw new InvalidArgumentException('Invalid WebSocket app attachment response identifier.');
        }

        if (strlen($name) > 63 || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException('Invalid WebSocket app attachment response field [name].');
        }

        if (! in_array($operation, ['attach', 'detach'], true)) {
            throw new InvalidArgumentException('Invalid WebSocket app attachment response field [operation].');
        }

        if ($keyCount < 0 || $keyCount > self::MAX_KEY_COUNT) {
            throw new InvalidArgumentException('Invalid WebSocket app attachment response field [key_count].');
        }
    }

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        return new self(
            instanceId: self::integer($data, 'instance_id'),
            name: self::string($data, 'name'),
            keys: self::keys($data),
            operation: self::string($data, 'operation'),
            changed: self::boolean($data, 'changed'),
            keyCount: self::integer($data, 'key_count'),
            requestId: GatewayRequestId::fromTransport($requestId) ?? '',
        );
    }

    /** @return array{instance_id: int, name: string, keys: list<string>, operation: string, changed: bool, key_count: int, request_id: string} */
    public function toArray(): array
    {
        return [
            'instance_id' => $this->instanceId,
            'name' => $this->name,
            'keys' => $this->keys,
            'operation' => $this->operation,
            'changed' => $this->changed,
            'key_count' => $this->keyCount,
            'request_id' => $this->requestId,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function keys(#[SensitiveParameter] array $data): array
    {
        $keys = $data['keys'] ?? null;

        if (! is_array($keys) || ! array_is_list($keys)) {
            throw new InvalidArgumentException('Invalid WebSocket app attachment response field [keys].');
        }

        foreach ($keys as $key) {
            if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw new InvalidArgumentException('Invalid WebSocket app attachment response field [keys].');
            }
        }

        return $keys;
    }

    /** @param array<string, mixed> $data */
    private static function integer(#[SensitiveParameter] array $data, string $key): int
    {
        if (! is_int($data[$key] ?? null)) {
            throw new InvalidArgumentException("Invalid WebSocket app attachment response field [{$key}].");
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function string(#[SensitiveParameter] array $data, string $key): string
    {
        if (! is_string($data[$key] ?? null)) {
            throw new InvalidArgumentException("Invalid WebSocket app attachment response field [{$key}].");
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function boolean(#[SensitiveParameter] array $data, string $key): bool
    {
        if (! is_bool($data[$key] ?? null)) {
            throw new InvalidArgumentException("Invalid WebSocket app attachment response field [{$key}].");
        }

        return $data[$key];
    }
}

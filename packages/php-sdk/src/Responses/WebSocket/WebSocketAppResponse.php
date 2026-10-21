<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\WebSocket;

use InvalidArgumentException;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

/** A Reverb app. The Gateway never returns its secret. */
final readonly class WebSocketAppResponse
{
    private const string NAME_PATTERN = '/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D';

    private const int IDENTIFIER_MAX_LENGTH = 64;

    /**
     * @param  list<int>  $instanceIds
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $appId,
        public string $key,
        public array $instanceIds,
        public string $requestId,
    ) {
        if ($id < 1) {
            throw new InvalidArgumentException('Invalid WebSocket app response identifier.');
        }

        if (strlen($name) > 63 || preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException('Invalid WebSocket app response field [name].');
        }

        foreach (['app_id' => $appId, 'key' => $key] as $field => $value) {
            if ($value === '' || strlen($value) > self::IDENTIFIER_MAX_LENGTH) {
                throw new InvalidArgumentException("Invalid WebSocket app response field [{$field}].");
            }
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
            id: self::integer($data, 'id'),
            name: self::string($data, 'name'),
            appId: self::string($data, 'app_id'),
            key: self::string($data, 'key'),
            instanceIds: self::instanceIds($data),
            requestId: GatewayRequestId::fromTransport($requestId) ?? '',
        );
    }

    /** @return array{id: int, name: string, app_id: string, key: string, instance_ids: list<int>, request_id: string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'app_id' => $this->appId,
            'key' => $this->key,
            'instance_ids' => $this->instanceIds,
            'request_id' => $this->requestId,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private static function instanceIds(#[SensitiveParameter] array $data): array
    {
        $ids = $data['instance_ids'] ?? null;

        if (! is_array($ids) || ! array_is_list($ids)) {
            throw new InvalidArgumentException('Invalid WebSocket app response field [instance_ids].');
        }

        foreach ($ids as $id) {
            if (! is_int($id) || $id < 1) {
                throw new InvalidArgumentException('Invalid WebSocket app response field [instance_ids].');
            }
        }

        return $ids;
    }

    /** @param array<string, mixed> $data */
    private static function integer(#[SensitiveParameter] array $data, string $key): int
    {
        if (! is_int($data[$key] ?? null)) {
            throw new InvalidArgumentException("Invalid WebSocket app response field [{$key}].");
        }

        return $data[$key];
    }

    /** @param array<string, mixed> $data */
    private static function string(#[SensitiveParameter] array $data, string $key): string
    {
        if (! is_string($data[$key] ?? null)) {
            throw new InvalidArgumentException("Invalid WebSocket app response field [{$key}].");
        }

        return $data[$key];
    }
}

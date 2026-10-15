<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Environment;

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class EnvironmentOperationResponse
{
    private const int MAX_KEY_COUNT = 1_024;

    private function __construct(
        public int $instanceId,
        public string $operation,
        public bool $changed,
        public int $keyCount,
        public string $requestId,
        public ?string $runtimeRefresh = null,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        string $expectedOperation,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $validRequestId = GatewayRequestId::fromTransport($requestId);

        if (
            ! self::hasExactFields($data)
            || (array_key_exists('runtime_refresh', $data) && ($expectedOperation !== 'sync' || ! in_array($data['runtime_refresh'], [
                'skipped_unchanged',
                'skipped_no_php_version',
                'skipped_no_current_release',
                'config_cache_and_opcache_refreshed',
                'opcache_refreshed_config_uncached',
            ], true)))
            || ! is_int($data['instance_id'])
            || $data['instance_id'] < 1
            || ! is_string($data['operation'])
            || ! in_array($expectedOperation, ['import', 'update', 'sync'], strict: true)
            || $data['operation'] !== $expectedOperation
            || ! is_bool($data['changed'])
            || ! is_int($data['key_count'])
            || $data['key_count'] < 0
            || $data['key_count'] > self::MAX_KEY_COUNT
            || $validRequestId === null
        ) {
            throw new GatewayApiException(
                'Gateway response contains invalid environment operation data.',
                requestId: $validRequestId,
            );
        }

        return new self(
            instanceId: $data['instance_id'],
            operation: $data['operation'],
            changed: $data['changed'],
            keyCount: $data['key_count'],
            requestId: $validRequestId,
            runtimeRefresh: $data['runtime_refresh'] ?? null,
        );
    }

    /** @return array{instance_id: int, operation: string, changed: bool, key_count: int, request_id: string, runtime_refresh?: string} */
    public function toArray(): array
    {
        return [
            'instance_id' => $this->instanceId,
            'operation' => $this->operation,
            'changed' => $this->changed,
            'key_count' => $this->keyCount,
            'request_id' => $this->requestId,
            ...($this->runtimeRefresh === null ? [] : ['runtime_refresh' => $this->runtimeRefresh]),
        ];
    }

    /** @param array<array-key, mixed> $data */
    private static function hasExactFields(#[SensitiveParameter] array $data): bool
    {
        $expected = ['instance_id', 'operation', 'changed', 'key_count'];

        if (array_key_exists('runtime_refresh', $data)) {
            $expected[] = 'runtime_refresh';
        }

        if (count($data) !== count($expected)) {
            return false;
        }

        return array_all(
            array_keys($data),
            static fn (mixed $key): bool => is_string($key) && in_array($key, $expected, strict: true),
        );
    }
}

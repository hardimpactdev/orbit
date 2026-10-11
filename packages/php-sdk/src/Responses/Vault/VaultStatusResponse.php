<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Vault;

use Orbit\Sdk\GatewayApiException;
use SensitiveParameter;

/**
 * @phpstan-type VaultReference array{id: string, name: string}
 * @phpstan-type VaultSkippedSource array{source: string, slug: string, username: string|null, reason: 'incomplete'|'password_unknown'}
 * @phpstan-type VaultError array{code: string, message: string}
 */
final readonly class VaultStatusResponse
{
    private const int MAX_SKIPPED = 10_000;

    /**
     * @param  VaultReference|null  $organization
     * @param  VaultReference|null  $collection
     * @param  list<VaultSkippedSource>  $skipped
     * @param  VaultError|null  $lastError
     */
    public function __construct(
        public bool $configured,
        public ?string $url,
        public ?string $email,
        public ?array $organization,
        public ?array $collection,
        public int $items,
        public int $pending,
        public array $skipped,
        public ?string $lastSyncedAt,
        public ?array $lastError,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $configured = $data['configured'] ?? null;
        $url = $data['url'] ?? null;
        $email = $data['email'] ?? null;
        $items = $data['items'] ?? null;
        $pending = $data['pending'] ?? null;
        $lastSyncedAt = $data['last_synced_at'] ?? null;

        if (
            ! is_bool($configured)
            || ! self::isOptionalString($url, 2048)
            || ! self::isOptionalString($email, 320)
            || ! is_int($items)
            || $items < 0
            || ! is_int($pending)
            || $pending < 0
            || ! self::isOptionalString($lastSyncedAt, 64)
        ) {
            throw self::invalid($requestId);
        }

        return new self(
            $configured,
            $url,
            $email,
            self::reference($data['organization'] ?? null, $requestId),
            self::reference($data['collection'] ?? null, $requestId),
            $items,
            $pending,
            self::skipped($data['skipped'] ?? null, $requestId),
            $lastSyncedAt,
            self::error($data['last_error'] ?? null, $requestId),
            $requestId,
        );
    }

    /**
     * @return array{
     *     configured: bool,
     *     url: string|null,
     *     email: string|null,
     *     organization: VaultReference|null,
     *     collection: VaultReference|null,
     *     items: int,
     *     pending: int,
     *     skipped: list<VaultSkippedSource>,
     *     last_synced_at: string|null,
     *     last_error: VaultError|null,
     *     request_id: string
     * }
     */
    public function toArray(): array
    {
        return [
            'configured' => $this->configured,
            'url' => $this->url,
            'email' => $this->email,
            'organization' => $this->organization,
            'collection' => $this->collection,
            'items' => $this->items,
            'pending' => $this->pending,
            'skipped' => $this->skipped,
            'last_synced_at' => $this->lastSyncedAt,
            'last_error' => $this->lastError,
            'request_id' => $this->requestId,
        ];
    }

    /** @return VaultReference|null */
    private static function reference(mixed $value, string $requestId): ?array
    {
        if ($value === null) {
            return null;
        }

        if (
            ! is_array($value)
            || ! self::isString($value['id'] ?? null, 255)
            || ! self::isString($value['name'] ?? null, 1000)
        ) {
            throw self::invalid($requestId);
        }

        return ['id' => $value['id'], 'name' => $value['name']];
    }

    /** @return list<VaultSkippedSource> */
    private static function skipped(mixed $value, string $requestId): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) > self::MAX_SKIPPED) {
            throw self::invalid($requestId);
        }

        $skipped = [];

        foreach ($value as $row) {
            if (
                ! is_array($row)
                || ! self::isString($row['source'] ?? null, 255)
                || ! self::isString($row['slug'] ?? null, 255)
                || ! self::isOptionalString($row['username'] ?? null, 255)
            ) {
                throw self::invalid($requestId);
            }

            $reason = match ($row['reason'] ?? null) {
                'incomplete' => 'incomplete',
                'password_unknown' => 'password_unknown',
                default => throw self::invalid($requestId),
            };

            $skipped[] = [
                'source' => $row['source'],
                'slug' => $row['slug'],
                'username' => $row['username'] ?? null,
                'reason' => $reason,
            ];
        }

        return $skipped;
    }

    /** @return VaultError|null */
    private static function error(mixed $value, string $requestId): ?array
    {
        if ($value === null) {
            return null;
        }

        if (
            ! is_array($value)
            || ! self::isString($value['code'] ?? null, 255)
            || ! self::isString($value['message'] ?? null, 2000)
        ) {
            throw self::invalid($requestId);
        }

        return ['code' => $value['code'], 'message' => $value['message']];
    }

    /** @phpstan-assert-if-true non-empty-string $value */
    private static function isString(mixed $value, int $maxLength): bool
    {
        return is_string($value) && $value !== '' && strlen($value) <= $maxLength;
    }

    /** @phpstan-assert-if-true non-empty-string|null $value */
    private static function isOptionalString(mixed $value, int $maxLength): bool
    {
        return $value === null || self::isString($value, $maxLength);
    }

    private static function invalid(string $requestId): GatewayApiException
    {
        return new GatewayApiException('Gateway response contains invalid vault status.', requestId: $requestId);
    }
}

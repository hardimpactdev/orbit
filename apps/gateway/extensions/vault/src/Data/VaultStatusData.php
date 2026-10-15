<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Data;

use Orbit\Extensions\Vault\Domain\SkippedCredential;

/** The Vaultwarden setup and the state of the published items. It never contains a secret. */
final readonly class VaultStatusData
{
    /**
     * @param  array{id: string, name: string}|null  $organization
     * @param  array{id: string, name: string}|null  $collection
     * @param  list<SkippedCredential>  $skipped
     * @param  array{code: string, message: string}|null  $lastError
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
    ) {}

    /** @return array<string, mixed> */
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
            'skipped' => array_map(static fn (SkippedCredential $skipped): array => $skipped->toArray(), $this->skipped),
            'last_synced_at' => $this->lastSyncedAt,
            'last_error' => $this->lastError,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Domain;

use Orbit\Extensions\Vault\Models\VaultItem;

/** What a sync must change: wanted items without a published one, changed items, and orphans. */
final readonly class VaultPlan
{
    /**
     * @param  list<DatabaseCredentialItem>  $creates
     * @param  list<array{item: DatabaseCredentialItem, published: VaultItem}>  $updates
     * @param  list<VaultItem>  $deletes
     * @param  array<string, string>  $fingerprints  keyed by source
     */
    public function __construct(
        public array $creates,
        public array $updates,
        public array $deletes,
        public array $fingerprints,
    ) {}

    /**
     * @param  array<string, DatabaseCredentialItem>  $wanted
     * @param  iterable<VaultItem>  $published
     * @param  list<string>  $forced  sources to rewrite even when their fingerprint matches
     */
    public static function for(
        VaultConfiguration $configuration,
        array $wanted,
        iterable $published,
        string $key,
        array $forced = [],
    ): self {
        $bySource = [];

        foreach ($published as $item) {
            $bySource[$item->source] = $item;
        }

        $creates = [];
        $updates = [];
        $fingerprints = [];

        foreach ($wanted as $source => $item) {
            $fingerprint = $item->fingerprint($configuration->organizationId, $configuration->collectionId, $key);
            $fingerprints[$source] = $fingerprint;
            $existing = $bySource[$source] ?? null;

            if (! $existing instanceof VaultItem) {
                $creates[] = $item;
            } elseif ($existing->fingerprint !== $fingerprint || in_array($source, $forced, true)) {
                $updates[] = ['item' => $item, 'published' => $existing];
            }
        }

        $deletes = array_values(array_filter(
            $bySource,
            static fn (VaultItem $item): bool => ! isset($wanted[$item->source]),
        ));

        return new self($creates, $updates, $deletes, $fingerprints);
    }

    public function pending(): int
    {
        return count($this->creates) + count($this->updates) + count($this->deletes);
    }
}

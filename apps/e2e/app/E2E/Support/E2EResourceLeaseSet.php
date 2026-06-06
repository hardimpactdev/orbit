<?php

declare(strict_types=1);

namespace App\E2E\Support;

final readonly class E2EResourceLeaseSet
{
    /**
     * @param  non-empty-list<E2EResourceLease>  $leases
     */
    public function __construct(
        private array $leases,
    ) {}

    public function host(): string
    {
        return $this->leases[0]->host();
    }

    public function slot(): int
    {
        return $this->leases[0]->slot();
    }

    /**
     * @return non-empty-list<E2EResourceLease>
     */
    public function leases(): array
    {
        return $this->leases;
    }

    public function release(): void
    {
        foreach ($this->leases as $lease) {
            $lease->release();
        }
    }
}

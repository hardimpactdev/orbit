<?php

declare(strict_types=1);

namespace App\Domain\Vault;

/** A MySQL or PostgreSQL credential that gets no item, and why. */
final readonly class SkippedCredential
{
    public const string Incomplete = 'incomplete';

    public const string PasswordUnknown = 'password_unknown';

    public function __construct(
        public string $source,
        public string $slug,
        public ?string $username,
        public string $reason,
    ) {}

    /** @return array{source: string, slug: string, username: string|null, reason: string} */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'slug' => $this->slug,
            'username' => $this->username,
            'reason' => $this->reason,
        ];
    }
}

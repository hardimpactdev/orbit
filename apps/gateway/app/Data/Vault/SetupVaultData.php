<?php

declare(strict_types=1);

namespace App\Data\Vault;

use SensitiveParameter;

/** The values `vault:setup` checks and stores. The organization and collection are a name or an ID. */
final readonly class SetupVaultData
{
    public function __construct(
        public string $url,
        public string $clientId,
        #[SensitiveParameter] public string $clientSecret,
        #[SensitiveParameter] public string $masterPassword,
        public string $organization,
        public string $collection,
    ) {}

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['url' => $this->url, 'clientId' => $this->clientId, 'organization' => $this->organization, 'collection' => $this->collection];
    }
}

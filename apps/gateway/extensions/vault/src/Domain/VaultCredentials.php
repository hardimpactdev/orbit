<?php

declare(strict_types=1);

namespace Orbit\Extensions\Vault\Domain;

use SensitiveParameter;

/** What the Gateway needs to log in to Vaultwarden and unlock its account. */
final readonly class VaultCredentials
{
    public function __construct(
        public string $url,
        public string $clientId,
        #[SensitiveParameter] public string $clientSecret,
        #[SensitiveParameter] public string $masterPassword,
        public string $deviceIdentifier,
    ) {}

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['url' => $this->url, 'clientId' => $this->clientId];
    }
}

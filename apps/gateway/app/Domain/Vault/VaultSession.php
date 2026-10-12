<?php

declare(strict_types=1);

namespace App\Domain\Vault;

use SensitiveParameter;

/**
 * One unlocked login: the access token, the organization keys, and what the account could reach
 * when it logged in. It lives only for one setup, sync, or teardown.
 */
final readonly class VaultSession
{
    /**
     * @param  list<VaultOrganization>  $organizations
     * @param  list<VaultCollection>  $collections
     * @param  array<string, VaultRemoteCipher>  $ciphers  keyed by ID
     * @param  array<string, string>  $organizationKeys  keyed by organization ID
     */
    public function __construct(
        public string $url,
        #[SensitiveParameter] public string $accessToken,
        public string $email,
        /** The account's user ID. Vaultwarden requires it as `encryptedFor` on every item write. */
        public string $userId,
        public array $organizations,
        public array $collections,
        public array $ciphers,
        #[SensitiveParameter] public array $organizationKeys,
    ) {}

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['url' => $this->url, 'email' => $this->email];
    }
}

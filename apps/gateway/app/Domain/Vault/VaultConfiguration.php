<?php

declare(strict_types=1);

namespace App\Domain\Vault;

/** The stored setup: the account, and the organization and collection the Gateway publishes into. */
final readonly class VaultConfiguration
{
    public function __construct(
        public VaultCredentials $credentials,
        public string $email,
        public string $organizationId,
        public string $organizationName,
        public string $collectionId,
        public string $collectionName,
    ) {}

    /** @return array<string, string> */
    public function toStoredArray(): array
    {
        return [
            'url' => $this->credentials->url,
            'client_id' => $this->credentials->clientId,
            'client_secret' => $this->credentials->clientSecret,
            'master_password' => $this->credentials->masterPassword,
            'device_identifier' => $this->credentials->deviceIdentifier,
            'email' => $this->email,
            'organization_id' => $this->organizationId,
            'organization_name' => $this->organizationName,
            'collection_id' => $this->collectionId,
            'collection_name' => $this->collectionName,
        ];
    }

    /** @param array<array-key, mixed> $stored */
    public static function fromStoredArray(array $stored): ?self
    {
        $keys = ['url', 'client_id', 'client_secret', 'master_password', 'device_identifier', 'email',
            'organization_id', 'organization_name', 'collection_id', 'collection_name'];
        $values = [];

        foreach ($keys as $key) {
            if (! is_string($stored[$key] ?? null) || $stored[$key] === '') {
                return null;
            }

            $values[$key] = $stored[$key];
        }

        return new self(
            new VaultCredentials(
                $values['url'],
                $values['client_id'],
                $values['client_secret'],
                $values['master_password'],
                $values['device_identifier'],
            ),
            $values['email'],
            $values['organization_id'],
            $values['organization_name'],
            $values['collection_id'],
            $values['collection_name'],
        );
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['url' => $this->credentials->url, 'organizationId' => $this->organizationId, 'collectionId' => $this->collectionId];
    }
}

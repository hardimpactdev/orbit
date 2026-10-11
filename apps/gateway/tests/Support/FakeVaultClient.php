<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Vault\VaultCipher;
use App\Domain\Vault\VaultClient;
use App\Domain\Vault\VaultCollection;
use App\Domain\Vault\VaultCredentials;
use App\Domain\Vault\VaultException;
use App\Domain\Vault\VaultOrganization;
use App\Domain\Vault\VaultRemoteCipher;
use App\Domain\Vault\VaultSession;

/** An in-memory Vaultwarden with one account, for feature tests. */
final class FakeVaultClient implements VaultClient
{
    public const string ClientSecret = 'vault-client-secret-sentinel';

    public const string MasterPassword = 'vault-master-password-sentinel';

    public const string OrganizationId = 'org-1';

    public const string CollectionId = 'col-1';

    /** @var array<string, array{organization: string, collections: list<string>, revision: int, cipher: VaultCipher}> */
    public array $items = [];

    /** @var list<VaultCollection> */
    public array $collections = [];

    public int $opens = 0;

    public ?VaultException $failOpen = null;

    public ?VaultException $failWrites = null;

    /** @var list<VaultCredentials> */
    public array $credentials = [];

    private int $next = 1;

    public function __construct()
    {
        $this->collections = [
            new VaultCollection(self::CollectionId, self::OrganizationId, 'Databases', false),
            new VaultCollection('col-2', self::OrganizationId, 'Read only', true),
        ];
    }

    public function open(VaultCredentials $credentials): VaultSession
    {
        $this->opens++;
        $this->credentials[] = $credentials;

        if ($this->failOpen instanceof VaultException) {
            throw $this->failOpen;
        }

        if ($credentials->clientSecret !== self::ClientSecret || $credentials->masterPassword !== self::MasterPassword) {
            throw new VaultException('vault.login_failed', 'Vaultwarden refused the API key.', 422);
        }

        $ciphers = [];

        foreach ($this->items as $id => $item) {
            $ciphers[$id] = new VaultRemoteCipher($id, $item['organization'], $item['collections'], $this->revision($item['revision']));
        }

        return new VaultSession(
            $credentials->url,
            'token',
            'gateway@example.test',
            [new VaultOrganization(self::OrganizationId, 'Orbit'), new VaultOrganization('org-2', 'Other')],
            $this->collections,
            $ciphers,
            [self::OrganizationId => str_repeat('k', 64), 'org-2' => str_repeat('o', 64)],
        );
    }

    public function create(VaultSession $session, string $organizationId, string $collectionId, VaultCipher $cipher): VaultRemoteCipher
    {
        $this->failIfAsked();
        $id = 'cipher-'.$this->next++;
        $this->items[$id] = ['organization' => $organizationId, 'collections' => [$collectionId], 'revision' => 1, 'cipher' => $cipher];

        return new VaultRemoteCipher($id, $organizationId, [$collectionId], $this->revision(1));
    }

    public function update(VaultSession $session, string $cipherId, string $organizationId, VaultCipher $cipher): VaultRemoteCipher
    {
        $this->failIfAsked();

        if (! isset($this->items[$cipherId])) {
            throw new VaultException('vault.item_missing', 'The Vaultwarden item is gone.', 404);
        }

        $this->items[$cipherId]['cipher'] = $cipher;
        $this->items[$cipherId]['revision']++;

        return new VaultRemoteCipher($cipherId, $organizationId, $this->items[$cipherId]['collections'], $this->revision($this->items[$cipherId]['revision']));
    }

    public function delete(VaultSession $session, string $cipherId): void
    {
        $this->failIfAsked();
        unset($this->items[$cipherId]);
    }

    /** Simulates a person editing an item in Vaultwarden. */
    public function touch(string $cipherId): void
    {
        $this->items[$cipherId]['revision'] += 100;
    }

    /** @return array<string, string> */
    public function fields(string $cipherId): array
    {
        $fields = [];

        foreach ($this->items[$cipherId]['cipher']->fields as $field) {
            $fields[$field['name']] = $field['value'];
        }

        return $fields;
    }

    private function failIfAsked(): void
    {
        if ($this->failWrites instanceof VaultException) {
            throw $this->failWrites;
        }
    }

    private function revision(int $revision): string
    {
        return sprintf('2026-10-11T00:00:%02d.000Z', $revision % 60).'#'.$revision;
    }
}

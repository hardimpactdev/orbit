<?php

declare(strict_types=1);

namespace App\Infrastructure\Vault;

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Vault\VaultCipher;
use App\Domain\Vault\VaultClient;
use App\Domain\Vault\VaultCollection;
use App\Domain\Vault\VaultCredentials;
use App\Domain\Vault\VaultException;
use App\Domain\Vault\VaultOrganization;
use App\Domain\Vault\VaultRemoteCipher;
use App\Domain\Vault\VaultSession;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Vaultwarden's Bitwarden-compatible API. The Gateway logs in with the account's personal API key,
 * derives the master key with PBKDF2, and unlocks the organization keys from /api/sync. Recent
 * Vaultwarden answers in camelCase and older releases in PascalCase, so reads accept both. TLS
 * trusts only the Orbit root CA, so credentials reach only a Vaultwarden behind an Orbit Route.
 */
final readonly class HttpVaultClient implements VaultClient
{
    private const int Pbkdf2 = 0;

    private const int ConfirmedMember = 2;

    private const int SecureNote = 2;

    private const int TextField = 0;

    private const int HiddenField = 1;

    /** Bitwarden's device type for the Linux CLI, the closest match for a server-side client. */
    private const int DeviceType = 25;

    /** Bitwarden's own default is 600,000. A higher count only ties up the scheduler. */
    private const int MaxIterations = 2_000_000;

    private const string SourceField = 'orbit.source';

    public function __construct(
        private BitwardenCrypto $crypto,
        private LeafCertificateSigner $certificates,
    ) {}

    public function open(VaultCredentials $credentials): VaultSession
    {
        $url = rtrim($credentials->url, '/');
        $token = $this->send(fn (PendingRequest $http): Response => $http->asForm()->post($url.'/identity/connect/token', [
            'grant_type' => 'client_credentials',
            'scope' => 'api',
            'client_id' => $credentials->clientId,
            'client_secret' => $credentials->clientSecret,
            'deviceType' => self::DeviceType,
            'deviceIdentifier' => $credentials->deviceIdentifier,
            'deviceName' => 'Orbit Gateway',
        ]), loginFailure: true);

        $accessToken = $this->string($token, 'access_token');

        if ($accessToken === null) {
            throw $this->loginFailed();
        }

        $sync = $this->send(fn (PendingRequest $http): Response => $http->withToken($accessToken)->get($url.'/api/sync', ['excludeDomains' => 'true']));
        $profile = $this->array($sync, 'profile') ?? [];
        $email = $this->string($profile, 'email');
        $encryptedUserKey = $this->string($profile, 'key');
        $encryptedPrivateKey = $this->string($profile, 'privateKey');

        if ($email === null || $encryptedUserKey === null || $encryptedPrivateKey === null) {
            throw $this->unexpected();
        }

        $prelogin = $this->send(fn (PendingRequest $http): Response => $http->post($url.'/identity/accounts/prelogin', ['email' => $email]));
        $kdf = $this->value($prelogin, 'kdf');
        $iterations = $this->value($prelogin, 'kdfIterations');

        if ($kdf !== self::Pbkdf2) {
            throw new VaultException(
                'vault.kdf_unsupported',
                'The Vaultwarden account uses Argon2id. Change its KDF to PBKDF2-SHA256 in Vaultwarden.',
                422,
            );
        }

        if (! is_int($iterations) || $iterations < 1) {
            throw $this->unexpected();
        }

        if ($iterations > self::MaxIterations) {
            throw new VaultException(
                'vault.kdf_unsupported',
                'The Vaultwarden account uses more than 2,000,000 PBKDF2 iterations.',
                422,
            );
        }

        $userKey = $this->crypto->decrypt(
            $encryptedUserKey,
            $this->crypto->masterKey($credentials->masterPassword, $email, $iterations),
        );
        $privateKey = $this->crypto->decrypt($encryptedPrivateKey, $userKey);

        $organizations = [];
        $organizationKeys = [];

        foreach ($this->list($profile, 'organizations') as $organization) {
            $id = $this->string($organization, 'id');
            $name = $this->string($organization, 'name');
            $key = $this->string($organization, 'key');

            if ($id === null || $name === null || $key === null || $this->value($organization, 'status') !== self::ConfirmedMember) {
                continue;
            }

            $organizations[] = new VaultOrganization($id, $name);
            $organizationKeys[$id] = $this->crypto->decryptWithPrivateKey($key, $privateKey);
        }

        $collections = [];

        foreach ($this->list($sync, 'collections') as $collection) {
            $id = $this->string($collection, 'id');
            $organizationId = $this->string($collection, 'organizationId');
            $name = $this->string($collection, 'name');

            if ($id === null || $organizationId === null || $name === null || ! isset($organizationKeys[$organizationId])) {
                continue;
            }

            $collections[] = new VaultCollection(
                $id,
                $organizationId,
                $this->crypto->decrypt($name, $organizationKeys[$organizationId]),
                $this->value($collection, 'readOnly') === true,
            );
        }

        $ciphers = [];

        foreach ($this->list($sync, 'ciphers') as $cipher) {
            $remote = $this->remoteCipher($cipher, $organizationKeys);

            if ($remote instanceof VaultRemoteCipher) {
                $ciphers[$remote->id] = $remote;
            }
        }

        return new VaultSession($url, $accessToken, $email, $organizations, $collections, $ciphers, $organizationKeys);
    }

    public function create(VaultSession $session, string $organizationId, string $collectionId, VaultCipher $cipher): VaultRemoteCipher
    {
        $body = [
            'cipher' => $this->cipherBody($session, $organizationId, $cipher),
            'collectionIds' => [$collectionId],
        ];

        return $this->remoteCipher($this->send(
            fn (PendingRequest $http): Response => $http->withToken($session->accessToken)->post($session->url.'/api/ciphers/create', $body),
        )) ?? throw $this->unexpected();
    }

    public function update(VaultSession $session, string $cipherId, string $organizationId, VaultCipher $cipher): VaultRemoteCipher
    {
        $body = $this->cipherBody($session, $organizationId, $cipher);

        return $this->remoteCipher($this->send(
            fn (PendingRequest $http): Response => $http->withToken($session->accessToken)->put($session->url.'/api/ciphers/'.rawurlencode($cipherId), $body),
            missing: true,
        )) ?? throw $this->unexpected();
    }

    public function delete(VaultSession $session, string $cipherId): void
    {
        try {
            $this->send(
                fn (PendingRequest $http): Response => $http->withToken($session->accessToken)->delete($session->url.'/api/ciphers/'.rawurlencode($cipherId)),
                missing: true,
            );
        } catch (VaultException $exception) {
            if ($exception->errorCode !== 'vault.item_missing') {
                throw $exception;
            }
        }
    }

    /** @return array<string, mixed> */
    private function cipherBody(VaultSession $session, string $organizationId, VaultCipher $cipher): array
    {
        $key = $session->organizationKeys[$organizationId] ?? throw new VaultException(
            'vault.organization_missing',
            'The Vaultwarden account is no longer a confirmed member of the organization.',
            422,
        );

        return [
            'type' => self::SecureNote,
            'organizationId' => $organizationId,
            'folderId' => null,
            'name' => $this->crypto->encrypt($cipher->name, $key),
            'notes' => $this->crypto->encrypt($cipher->notes, $key),
            'favorite' => false,
            'reprompt' => 0,
            'secureNote' => ['type' => 0],
            'fields' => array_map(fn (array $field): array => [
                'type' => $field['hidden'] ? self::HiddenField : self::TextField,
                'name' => $this->crypto->encrypt($field['name'], $key),
                'value' => $this->crypto->encrypt($field['value'], $key),
                'linkedId' => null,
            ], $cipher->fields),
            'lastKnownRevisionDate' => null,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $cipher
     * @param  array<string, string>  $organizationKeys
     */
    private function remoteCipher(array $cipher, array $organizationKeys = []): ?VaultRemoteCipher
    {
        $id = $this->string($cipher, 'id');

        if ($id === null) {
            return null;
        }

        $collectionIds = array_values(array_filter(
            $this->rawList($cipher, 'collectionIds'),
            is_string(...),
        ));
        $organizationId = $this->string($cipher, 'organizationId');

        return new VaultRemoteCipher(
            $id,
            $organizationId,
            $collectionIds,
            $this->string($cipher, 'revisionDate'),
            $organizationId === null || ! isset($organizationKeys[$organizationId])
                ? null
                : $this->source($cipher, $organizationKeys[$organizationId]),
            $this->value($cipher, 'deletedDate') !== null,
        );
    }

    /**
     * The decrypted `orbit.source` field of an organization item, or null. An item with its own key
     * encrypts its fields with that key, which the organization key wraps.
     *
     * @param  array<array-key, mixed>  $cipher
     */
    private function source(array $cipher, string $organizationKey): ?string
    {
        try {
            $wrapped = $this->string($cipher, 'key');
            $key = $wrapped === null ? $organizationKey : $this->crypto->decrypt($wrapped, $organizationKey);

            foreach ($this->list($cipher, 'fields') as $field) {
                $name = $this->string($field, 'name');
                $value = $this->string($field, 'value');

                if ($name !== null && $value !== null && $this->crypto->decrypt($name, $key) === self::SourceField) {
                    return $this->crypto->decrypt($value, $key);
                }
            }
        } catch (VaultException) {
            return null;
        }

        return null;
    }

    /**
     * Sends one request that trusts only the Orbit root CA. The CA sits in a private temporary
     * file made for this request and removed after it, so no other account can swap the trust.
     *
     * @param  callable(PendingRequest): Response  $request
     * @return array<array-key, mixed>
     */
    private function send(callable $request, bool $loginFailure = false, bool $missing = false): array
    {
        $trust = tempnam(sys_get_temp_dir(), 'orbit-vault-ca-');

        if (! is_string($trust) || file_put_contents($trust, $this->certificates->rootCertificate()) === false) {
            throw new VaultException('vault.unreachable', 'The Gateway could not prepare its Vaultwarden trust.', 502);
        }

        try {
            $response = $request(Http::timeout(15)->connectTimeout(5)->acceptJson()->withOptions(['verify' => $trust]));
        } catch (ConnectionException $exception) {
            throw new VaultException('vault.unreachable', 'Vaultwarden did not answer.', 502, $exception);
        } finally {
            unlink($trust);
        }

        if ($missing && ($response->status() === 404 || ($response->status() === 400 && str_contains(strtolower($response->body()), "doesn't exist")))) {
            throw new VaultException('vault.item_missing', 'The Vaultwarden item is gone.', 404);
        }

        if ($loginFailure && in_array($response->status(), [400, 401], true)) {
            throw $this->loginFailed();
        }

        if (! $response->successful()) {
            throw new VaultException('vault.unreachable', "Vaultwarden answered with HTTP {$response->status()}.", 502);
        }

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<array-key, mixed> $data */
    private function value(array $data, string $key): mixed
    {
        return $data[$key] ?? $data[ucfirst($key)] ?? null;
    }

    /** @param array<array-key, mixed> $data */
    private function string(array $data, string $key): ?string
    {
        $value = $this->value($data, $key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>|null
     */
    private function array(array $data, string $key): ?array
    {
        $value = $this->value($data, $key);

        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<array<array-key, mixed>>
     */
    private function list(array $data, string $key): array
    {
        return array_values(array_filter($this->rawList($data, $key), is_array(...)));
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return list<mixed>
     */
    private function rawList(array $data, string $key): array
    {
        $value = $this->value($data, $key);

        return is_array($value) ? array_values($value) : [];
    }

    private function loginFailed(): VaultException
    {
        return new VaultException('vault.login_failed', 'Vaultwarden refused the API key.', 422);
    }

    private function unexpected(): VaultException
    {
        return new VaultException('vault.unreachable', 'Vaultwarden answered with an unexpected response.', 502);
    }

    /** @return array<string, never> */
    public function __debugInfo(): array
    {
        return [];
    }
}

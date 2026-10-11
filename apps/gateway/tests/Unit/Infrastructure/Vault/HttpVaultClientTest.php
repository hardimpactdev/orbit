<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Vault\VaultCipher;
use App\Domain\Vault\VaultCredentials;
use App\Domain\Vault\VaultException;
use App\Infrastructure\Vault\BitwardenCrypto;
use App\Infrastructure\Vault\HttpVaultClient;
use GuzzleHttp\Handler\StreamHandler;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

/**
 * A Vaultwarden account built with real keys: a user key under the PBKDF2 master key, an RSA key
 * pair under the user key, and an organization key under the RSA public key.
 *
 * @return array{sync: array<string, mixed>, organizationKey: string}
 */
function vault_account(BitwardenCrypto $crypto, string $password, int $kdf = 0, int $iterations = 5000): array
{
    $email = 'Gateway@Example.test';
    $userKey = random_bytes(64);
    $organizationKey = random_bytes(64);
    $rsa = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($rsa, $pem);
    $der = base64_decode(preg_replace('/-----[^-]+-----|\s/', '', $pem));
    openssl_public_encrypt($organizationKey, $wrapped, openssl_pkey_get_details($rsa)['key'], OPENSSL_PKCS1_OAEP_PADDING);
    $itemKey = random_bytes(64);
    $source = fn (string $value, string $key): array => [[
        'type' => 0,
        'name' => $crypto->encrypt('orbit.source', $key),
        'value' => $crypto->encrypt($value, $key),
    ]];

    Http::fake([
        'https://vault.test/identity/connect/token' => static fn (Request $request) => $request['client_secret'] === 'secret'
            ? Http::response(['access_token' => 'access-token', 'token_type' => 'Bearer'])
            : Http::response(['error' => 'invalid_client'], 400),
        'https://vault.test/identity/accounts/prelogin' => Http::response(['kdf' => $kdf, 'kdfIterations' => $iterations]),
        'https://vault.test/api/sync*' => Http::response([
            'profile' => [
                'email' => $email,
                'key' => $crypto->encrypt($userKey, $crypto->masterKey($password, $email, 5000)),
                'privateKey' => $crypto->encrypt($der, $userKey),
                'organizations' => [
                    ['id' => 'org-1', 'name' => 'Orbit', 'key' => '4.'.base64_encode($wrapped), 'status' => 2],
                    ['id' => 'org-2', 'name' => 'Invited', 'key' => null, 'status' => 0],
                ],
            ],
            'collections' => [
                ['id' => 'col-1', 'organizationId' => 'org-1', 'name' => $crypto->encrypt('Databases', $organizationKey), 'readOnly' => false],
            ],
            'ciphers' => [
                ['id' => 'c-1', 'organizationId' => 'org-1', 'collectionIds' => ['col-1'], 'revisionDate' => 'r1', 'deletedDate' => null,
                    'fields' => $source('database-connection:5', $organizationKey)],
                ['id' => 'c-2', 'organizationId' => 'org-1', 'collectionIds' => ['col-1'], 'revisionDate' => 'r1', 'deletedDate' => '2026-10-11',
                    'fields' => $source('database-connection:5', $organizationKey)],
                ['id' => 'c-3', 'organizationId' => 'org-1', 'collectionIds' => ['col-1'], 'revisionDate' => 'r1', 'deletedDate' => null,
                    'key' => $crypto->encrypt($itemKey, $organizationKey), 'fields' => $source('database-user:7', $itemKey)],
                ['id' => 'c-4', 'organizationId' => null, 'collectionIds' => [], 'revisionDate' => 'r1', 'deletedDate' => null,
                    'fields' => [['type' => 0, 'name' => '2.bad|bad|bad', 'value' => '2.bad|bad|bad']]],
            ],
        ]),
        'https://vault.test/api/ciphers/create' => Http::response(['id' => 'c-new', 'organizationId' => 'org-1', 'collectionIds' => ['col-1'], 'revisionDate' => 'r2']),
        'https://vault.test/api/ciphers/c-1' => static fn (Request $request) => $request->method() === 'DELETE'
            ? Http::response(null, 200)
            : Http::response(['Id' => 'c-1', 'OrganizationId' => 'org-1', 'CollectionIds' => ['col-1'], 'RevisionDate' => 'r3']),
        'https://vault.test/api/ciphers/gone' => Http::response(['message' => "Cipher doesn't exist"], 400),
    ]);

    return ['organizationKey' => $organizationKey];
}

function vault_credentials(string $secret = 'secret', string $password = 'correct horse'): VaultCredentials
{
    return new VaultCredentials('https://vault.test', 'user.gateway', $secret, $password, 'device-1');
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    $this->crypto = new BitwardenCrypto;
    $this->root = "-----BEGIN CERTIFICATE-----\nORBIT-ROOT-".bin2hex(random_bytes(8))."\n-----END CERTIFICATE-----\n";
    $this->rootPath = '/var/lib/orbit/ca/root.pem';
    $this->client = new HttpVaultClient($this->crypto, new class($this->root) implements LeafCertificateSigner
    {
        public function __construct(private string $root) {}

        public function sign(string $hostname, string $certificateRequest): string
        {
            return '';
        }

        public function rootCertificate(): string
        {
            return $this->root;
        }
    }, $this->rootPath);
});

describe('BitwardenCrypto', function (): void {
    it('round-trips a type 2 string and rejects a changed one', function (): void {
        $key = random_bytes(64);
        $encrypted = $this->crypto->encrypt('secret value', $key);

        expect($encrypted)->toStartWith('2.')
            ->and($this->crypto->decrypt($encrypted, $key))->toBe('secret value');

        [$iv, $data, $mac] = explode('|', substr($encrypted, 2));
        $tampered = '2.'.$iv.'|'.base64_encode(strrev(base64_decode($data))).'|'.$mac;

        expect(fn () => $this->crypto->decrypt($tampered, $key))->toThrow(VaultException::class);
    });

    it('derives a stretched 64-byte master key from a normalized email', function (): void {
        $key = $this->crypto->masterKey('pw', ' User@Example.test ', 1000);

        expect($key)->toBe($this->crypto->masterKey('pw', 'user@example.test', 1000))
            ->and(strlen($key))->toBe(64);
    });
});

describe('HttpVaultClient', function (): void {
    it('unlocks the account and reads organizations, collections, and live items', function (): void {
        $account = vault_account($this->crypto, 'correct horse');

        $session = $this->client->open(vault_credentials());

        expect($session->email)->toBe('Gateway@Example.test')
            ->and($session->accessToken)->toBe('access-token')
            ->and(array_map(fn ($organization) => $organization->id, $session->organizations))->toBe(['org-1'])
            ->and($session->organizationKeys['org-1'])->toBe($account['organizationKey'])
            ->and($session->collections[0]->name)->toBe('Databases')
            ->and($session->collections[0]->readOnly)->toBeFalse()
            ->and(array_keys($session->ciphers))->toBe(['c-1', 'c-2', 'c-3', 'c-4'])
            ->and(array_map(fn ($cipher) => [$cipher->source, $cipher->deleted], $session->ciphers))->toBe([
                'c-1' => ['database-connection:5', false],
                'c-2' => ['database-connection:5', true],
                'c-3' => ['database-user:7', false],
                'c-4' => [null, false],
            ]);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://vault.test/identity/connect/token'
            && $request['grant_type'] === 'client_credentials'
            && $request['deviceIdentifier'] === 'device-1');
    });

    it('refuses a wrong API key, a wrong master password, and Argon2id', function (): void {
        vault_account($this->crypto, 'correct horse');
        expect(fn () => $this->client->open(vault_credentials(secret: 'wrong')))
            ->toThrow(fn (VaultException $exception) => expect($exception->errorCode)->toBe('vault.login_failed'));
        expect(fn () => $this->client->open(vault_credentials(password: 'wrong')))
            ->toThrow(fn (VaultException $exception) => expect($exception->errorCode)->toBe('vault.login_failed'));
    });

    it('trusts only the Orbit root CA file in the Gateway state directory', function (): void {
        vault_account($this->crypto, 'correct horse');
        $verify = [];
        Http::fake(function (Request $request, array $options) use (&$verify) {
            $verify[] = $options['verify'] ?? null;

            return null;
        });

        $this->client->open(vault_credentials());

        expect($verify)->not->toBeEmpty()
            ->and(array_unique($verify))->toBe([$this->rootPath]);
    });

    it('sends through the stream handler, because cURL keeps the system CA directory beside the CA file', function (): void {
        $request = (fn (): PendingRequest => $this->http())->call($this->client);

        expect((fn (): mixed => $this->handler)->call($request))->toBeInstanceOf(StreamHandler::class);
    });

    it('never trusts a CA file another account planted in the temporary directory', function (): void {
        $planted = sys_get_temp_dir().'/orbit-vault-root-ca-'.hash('sha256', $this->root).'.pem';
        file_put_contents($planted, "-----BEGIN CERTIFICATE-----\nATTACKER\n-----END CERTIFICATE-----\n");
        vault_account($this->crypto, 'correct horse');
        $verify = [];
        Http::fake(function (Request $request, array $options) use (&$verify) {
            $verify[] = $options['verify'] ?? null;

            return null;
        });

        try {
            $this->client->open(vault_credentials());
        } finally {
            unlink($planted);
        }

        expect($verify)->not->toContain($planted)
            ->and(array_unique($verify))->toBe([$this->rootPath]);
    });

    it('sends nothing when the Orbit root CA does not validate', function (): void {
        vault_account($this->crypto, 'correct horse');
        $client = new HttpVaultClient($this->crypto, new class implements LeafCertificateSigner
        {
            public function sign(string $hostname, string $certificateRequest): string
            {
                return '';
            }

            public function rootCertificate(): string
            {
                throw new RuntimeException('Orbit root CA material is invalid.');
            }
        }, $this->rootPath);

        expect(fn () => $client->open(vault_credentials()))->toThrow(RuntimeException::class, 'Orbit root CA material is invalid.');
        Http::assertNothingSent();
    });

    it('refuses more than 2,000,000 PBKDF2 iterations', function (): void {
        vault_account($this->crypto, 'correct horse', iterations: 2_000_001);

        expect(fn () => $this->client->open(vault_credentials()))
            ->toThrow(fn (VaultException $exception) => expect($exception->errorCode)->toBe('vault.kdf_unsupported'));
    });

    it('refuses an Argon2id account', function (): void {
        vault_account($this->crypto, 'correct horse', kdf: 1);

        expect(fn () => $this->client->open(vault_credentials()))
            ->toThrow(fn (VaultException $exception) => expect($exception->errorCode)->toBe('vault.kdf_unsupported'));
    });

    it('creates a Secure Note encrypted with the organization key', function (): void {
        $account = vault_account($this->crypto, 'correct horse');
        $session = $this->client->open(vault_credentials());

        $remote = $this->client->create($session, 'org-1', 'col-1', new VaultCipher('ohdear', 'Managed by Orbit.', [
            ['name' => 'type', 'value' => 'database', 'hidden' => false],
            ['name' => 'password', 'value' => 'db-secret', 'hidden' => true],
        ]));

        expect($remote->id)->toBe('c-new')->and($remote->revisionDate)->toBe('r2');

        Http::assertSent(function (Request $request) use ($account): bool {
            if ($request->url() !== 'https://vault.test/api/ciphers/create') {
                return false;
            }

            $cipher = $request['cipher'];
            $decrypt = fn (string $value): string => $this->crypto->decrypt($value, $account['organizationKey']);

            expect($request->hasHeader('Authorization', 'Bearer access-token'))->toBeTrue()
                ->and($request['collectionIds'])->toBe(['col-1'])
                ->and($cipher['type'])->toBe(2)
                ->and($cipher['organizationId'])->toBe('org-1')
                ->and($cipher['secureNote'])->toBe(['type' => 0])
                ->and($decrypt($cipher['name']))->toBe('ohdear')
                ->and($decrypt($cipher['notes']))->toBe('Managed by Orbit.')
                ->and(array_map(fn (array $field): array => [$field['type'], $decrypt($field['name']), $decrypt($field['value'])], $cipher['fields']))
                ->toBe([[0, 'type', 'database'], [1, 'password', 'db-secret']])
                ->and($request->body())->not->toContain('db-secret');

            return true;
        });
    });

    it('updates and deletes items, and reports a missing item', function (): void {
        vault_account($this->crypto, 'correct horse');
        $session = $this->client->open(vault_credentials());
        $cipher = new VaultCipher('ohdear', 'notes', []);

        expect($this->client->update($session, 'c-1', 'org-1', $cipher)->revisionDate)->toBe('r3');

        $this->client->delete($session, 'c-1');
        $this->client->delete($session, 'gone');

        expect(fn () => $this->client->update($session, 'gone', 'org-1', $cipher))
            ->toThrow(fn (VaultException $exception) => expect($exception->errorCode)->toBe('vault.item_missing'));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && $request->url() === 'https://vault.test/api/ciphers/c-1');
    });

    it('reports an unreachable server', function (): void {
        Http::fake(['*' => Http::failedConnection()]);

        expect(fn () => $this->client->open(vault_credentials()))
            ->toThrow(fn (VaultException $exception) => expect($exception->errorCode)->toBe('vault.unreachable'));
    });
});

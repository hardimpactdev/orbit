<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use GuzzleHttp\Handler\StreamHandler;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Orbit\Extensions\Vault\Domain\VaultCredentials;
use Orbit\Extensions\Vault\Domain\VaultException;
use Orbit\Extensions\Vault\Infrastructure\BitwardenCrypto;
use Orbit\Extensions\Vault\Infrastructure\HttpVaultClient;

pest()->group('subprocess');

require_once __DIR__.'/../../../../Support/OrbitHttps.php';

beforeEach(function (): void {
    Http::allowStrayRequests();
    $this->servers = [];
    $this->directory = sys_get_temp_dir().'/orbit-vault-trust-'.Str::random(8);
    mkdir($this->directory);
    $this->orbitRoot = orbit_https_ca($this->directory, 'orbit');

    // The system trust store: OpenSSL's default verify paths read SSL_CERT_FILE, so the test CA
    // sits in the same store as `/etc/ssl/certs` without touching the host.
    $this->systemCertFile = getenv('SSL_CERT_FILE');
    putenv('SSL_CERT_FILE='.orbit_https_ca($this->directory, 'system'));

    $root = (string) file_get_contents($this->orbitRoot);
    $this->client = new HttpVaultClient(new BitwardenCrypto, new class($root) implements LeafCertificateSigner
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
    }, $this->orbitRoot);
});

afterEach(function (): void {
    foreach ($this->servers as $process) {
        proc_terminate($process);
        proc_close($process);
    }

    putenv($this->systemCertFile === false ? 'SSL_CERT_FILE' : 'SSL_CERT_FILE='.$this->systemCertFile);
    File::deleteDirectory($this->directory);
});

function vault_trust_credentials(int $port): VaultCredentials
{
    return new VaultCredentials("https://127.0.0.1:{$port}", 'user.gateway', 'secret', 'correct horse', 'device-1');
}

it('reaches a Vaultwarden with a certificate from the Orbit root CA', function (): void {
    $port = orbit_https_server($this->servers, $this->directory, 'orbit');

    expect(fn () => $this->client->open(vault_trust_credentials($port)))
        ->toThrow(fn (VaultException $exception) => expect($exception->errorCode)->toBe('vault.login_failed'));
});

it('rejects a certificate from a CA in the system trust store', function (): void {
    $port = orbit_https_server($this->servers, $this->directory, 'system');

    // Control: a client with the system store accepts the server.
    expect(Http::setHandler(new StreamHandler)->get("https://127.0.0.1:{$port}/")->status())->toBe(400);

    expect(fn () => $this->client->open(vault_trust_credentials($port)))
        ->toThrow(fn (VaultException $exception) => expect($exception->errorCode)->toBe('vault.unreachable'));
});

it('bounds a Vault request even when the peer keeps sending headers', function (): void {
    $port = orbit_https_server($this->servers, $this->directory, 'orbit', 'slow-headers');
    $started = hrtime(true);
    $failure = null;

    try {
        $this->client->open(vault_trust_credentials($port));
    } catch (Throwable $exception) {
        $failure = $exception;
    }

    expect((hrtime(true) - $started) / 1e9)->toBeLessThan(17);
    expect($failure)->toBeInstanceOf(VaultException::class);

    if ($failure instanceof VaultException) {
        expect($failure->errorCode)->toBe('vault.unreachable');
    }
});

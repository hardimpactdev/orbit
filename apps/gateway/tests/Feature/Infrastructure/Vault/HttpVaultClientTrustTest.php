<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Vault\VaultCredentials;
use App\Domain\Vault\VaultException;
use App\Infrastructure\Vault\BitwardenCrypto;
use App\Infrastructure\Vault\HttpVaultClient;
use GuzzleHttp\Handler\StreamHandler;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

pest()->group('subprocess');

/**
 * Writes a self-signed CA certificate for 127.0.0.1 and its key to $directory/$name.{pem,key}.
 * The server presents the CA certificate itself, so trusting that CA is enough to accept it.
 */
function vault_trust_ca(string $directory, string $name): string
{
    $config = $directory.'/openssl.cnf';
    file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nbasicConstraints=critical,CA:TRUE\nsubjectAltName=IP:127.0.0.1\nkeyUsage=digitalSignature,keyCertSign\n");
    $options = ['config' => $config, 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'digest_alg' => 'sha256', 'x509_extensions' => 'v3'];
    $key = openssl_pkey_new($options);
    $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => "{$name} test CA"], $key, $options), null, $key, 1, $options);
    openssl_x509_export_to_file($certificate, "{$directory}/{$name}.pem");
    openssl_pkey_export_to_file($key, "{$directory}/{$name}.key", null, ['config' => $config]);

    return "{$directory}/{$name}.pem";
}

/**
 * Starts the fixture server with the named CA's certificate, adds it to $servers, and returns its port.
 *
 * @param  list<resource>  $servers
 */
function vault_trust_server(array &$servers, string $directory, string $name): int
{
    $process = proc_open(
        [PHP_BINARY, base_path('tests/Fixtures/Vault/https-server.php'), "{$directory}/{$name}.pem", "{$directory}/{$name}.key"],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    if (! is_resource($process)) {
        throw new RuntimeException('The test HTTPS server did not start.');
    }

    $servers[] = $process;
    $line = (string) fgets($pipes[1]);

    if (! str_starts_with($line, 'port=')) {
        throw new RuntimeException('The test HTTPS server did not listen: '.stream_get_contents($pipes[2]));
    }

    return (int) substr(trim($line), 5);
}

beforeEach(function (): void {
    Http::allowStrayRequests();
    $this->servers = [];
    $this->directory = sys_get_temp_dir().'/orbit-vault-trust-'.Str::random(8);
    mkdir($this->directory);
    $this->orbitRoot = vault_trust_ca($this->directory, 'orbit');

    // The system trust store: OpenSSL's default verify paths read SSL_CERT_FILE, so the test CA
    // sits in the same store as `/etc/ssl/certs` without touching the host.
    $this->systemCertFile = getenv('SSL_CERT_FILE');
    putenv('SSL_CERT_FILE='.vault_trust_ca($this->directory, 'system'));

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
    $port = vault_trust_server($this->servers, $this->directory, 'orbit');

    expect(fn () => $this->client->open(vault_trust_credentials($port)))
        ->toThrow(fn (VaultException $exception) => expect($exception->errorCode)->toBe('vault.login_failed'));
});

it('rejects a certificate from a CA in the system trust store', function (): void {
    $port = vault_trust_server($this->servers, $this->directory, 'system');

    // Control: a client with the system store accepts the server.
    expect(Http::setHandler(new StreamHandler)->get("https://127.0.0.1:{$port}/")->status())->toBe(400);

    expect(fn () => $this->client->open(vault_trust_credentials($port)))
        ->toThrow(fn (VaultException $exception) => expect($exception->errorCode)->toBe('vault.unreachable'));
});

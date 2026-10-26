<?php

declare(strict_types=1);

use App\Domain\Certificates\GatewayCertificatePaths;
use App\Infrastructure\Certificates\OpenSslGatewayCertificateValidator;
use App\Infrastructure\Certificates\OrbitLeafCertificate;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\FixtureProcessRunner;
use Tests\Support\RootCaFixture;

beforeAll(function (): void {
    RootCaFixture::prepare();
    RootCaFixture::prepare('alternate');
});

/**
 * Signs a leaf for gateway.orbit at 10.44.0.1 the way the issuer does, with one property changed.
 *
 * @param  array{root?: string, days?: int, names?: string, extensions?: array<string, string>, key?: string, foreign_key?: bool}  $leaf
 */
function gateway_validator_leaf(string $home, array $leaf = []): GatewayCertificatePaths
{
    $signing = $home.'/signing-'.bin2hex(random_bytes(4));
    $version = $home.'/version-'.bin2hex(random_bytes(4));
    mkdir($signing, 0o700);
    mkdir($version, 0o700);
    RootCaFixture::install($signing, $leaf['root'] ?? 'primary');
    $paths = new GatewayCertificatePaths(privateKeyPath: $version.'/gateway.key', certificatePath: $version.'/gateway.pem');
    new Process(match ($leaf['key'] ?? '2048') {
        'ec' => ['openssl', 'genpkey', '-algorithm', 'EC', '-pkeyopt', 'ec_paramgen_curve:prime256v1', '-out', $paths->privateKeyPath],
        default => ['openssl', 'genrsa', '-out', $paths->privateKeyPath, $leaf['key'] ?? '2048'],
    })->mustRun();
    new Process(['openssl', 'req', '-new', '-key', $paths->privateKeyPath, '-out', $version.'/gateway.csr', '-subj', '/CN=gateway.orbit'])->mustRun();
    file_put_contents($version.'/gateway.ext', strtr(
        OrbitLeafCertificate::extensions('gateway', $leaf['names'] ?? 'DNS:gateway.orbit,IP:10.44.0.1'),
        $leaf['extensions'] ?? [],
    ));
    new Process([
        'openssl', 'x509', '-req', '-in', $version.'/gateway.csr',
        '-CA', $signing.'/root.pem', '-CAkey', $signing.'/root.key', '-set_serial', '0x'.bin2hex(random_bytes(16)),
        '-out', $paths->certificatePath, '-days', (string) ($leaf['days'] ?? OrbitLeafCertificate::ValidityDays),
        '-sha256', '-extfile', $version.'/gateway.ext', '-extensions', 'gateway',
    ])->mustRun();

    if ($leaf['foreign_key'] ?? false) {
        new Process(['openssl', 'genrsa', '-out', $paths->privateKeyPath, '2048'])->mustRun();
    }

    return $paths;
}

/** @param  array{root?: string, days?: int, names?: string, extensions?: array<string, string>, key?: string, foreign_key?: bool}  $leaf */
function gateway_validator_matches(array $leaf, string $hostname = 'gateway.orbit', string $address = '10.44.0.1'): bool
{
    $home = sys_get_temp_dir().'/orbit-gateway-validator-'.bin2hex(random_bytes(8));
    mkdir($home.'/ca', 0o700, true);

    try {
        RootCaFixture::install($home.'/ca');
        $paths = gateway_validator_leaf($home, $leaf);

        return new OpenSslGatewayCertificateValidator(new FixtureProcessRunner)
            ->matches($paths, $hostname, $address, $home.'/ca/root.pem');
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
}

it('accepts a leaf that the issuer would sign for the hostname and address', function (): void {
    expect(gateway_validator_matches([]))->toBeTrue();
});

it('compares the address, not its spelling', function (): void {
    expect(gateway_validator_matches(['names' => 'DNS:gateway.orbit,IP:fd00::1'], address: 'fd00:0:0:0::1'))->toBeTrue();
});

it('rejects a leaf for another identity', function (string $hostname, string $address): void {
    expect(gateway_validator_matches([], $hostname, $address))->toBeFalse();
})->with([
    'another hostname' => ['other.orbit', '10.44.0.1'],
    'another address' => ['gateway.orbit', '10.44.0.2'],
]);

it('rejects a leaf that differs from the Orbit leaf policy', function (array $leaf): void {
    expect(gateway_validator_matches($leaf))->toBeFalse();
})->with([
    'signed by another root' => [['root' => 'alternate']],
    'a key of another certificate' => [['foreign_key' => true]],
    'an EC key' => [['key' => 'ec']],
    'an RSA 3072 key' => [['key' => '3072']],
    'within thirty days of expiry' => [['days' => 29]],
    'longer than the leaf validity' => [['days' => OrbitLeafCertificate::ValidityDays + 1]],
    'no address' => [['names' => 'DNS:gateway.orbit']],
    'an extra name' => [['names' => 'DNS:gateway.orbit,DNS:extra.orbit,IP:10.44.0.1']],
    'non-critical basic constraints' => [['extensions' => ['critical,CA:FALSE' => 'CA:FALSE']]],
    'a CA leaf' => [['extensions' => ['critical,CA:FALSE' => 'critical,CA:TRUE']]],
    'non-critical key usage' => [['extensions' => ['keyUsage = critical,' => 'keyUsage = ']]],
    'an extra key usage' => [['extensions' => ['keyEncipherment' => 'keyEncipherment,keyCertSign']]],
    'no server authentication' => [['extensions' => ['serverAuth' => 'clientAuth']]],
]);

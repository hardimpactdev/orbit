<?php

declare(strict_types=1);

use App\Infrastructure\Certificates\OrbitRootCa;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\RootCaFixture;

beforeAll(function (): void {
    RootCaFixture::prepare();
    RootCaFixture::prepare('alternate');
    RootCaFixture::prepare('not-a-ca', extensions: ['basicConstraints=critical,CA:FALSE']);
});

/** @return array{certificate: string, key: string} */
function orbit_root_ca_pair(string $name = 'primary'): array
{
    $home = sys_get_temp_dir().'/orbit-root-ca-rule-'.bin2hex(random_bytes(8));
    mkdir($home, 0o700);

    try {
        RootCaFixture::install($home, $name);

        return [
            'certificate' => (string) file_get_contents($home.'/root.pem'),
            'key' => (string) file_get_contents($home.'/root.key'),
        ];
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
}

it('accepts a matching RSA 4096 CA pair on both bounds of its validity window', function (): void {
    $pair = orbit_root_ca_pair();
    $details = openssl_x509_parse($pair['certificate']);

    foreach ([$details['validFrom_time_t'], time(), $details['validTo_time_t']] as $now) {
        expect(OrbitRootCa::pairIsValid($pair['certificate'], $pair['key'], $now))->toBeTrue();
    }
});

it('rejects a pair outside its validity window', function (): void {
    $pair = orbit_root_ca_pair();
    $details = openssl_x509_parse($pair['certificate']);

    foreach ([$details['validFrom_time_t'] - 1, $details['validTo_time_t'] + 1] as $now) {
        expect(OrbitRootCa::pairIsValid($pair['certificate'], $pair['key'], $now))->toBeFalse();
    }
});

it('rejects a root certificate without CA:TRUE', function (): void {
    $pair = orbit_root_ca_pair('not-a-ca');

    expect(openssl_x509_parse($pair['certificate'])['extensions']['basicConstraints'])->toBe('CA:FALSE')
        ->and(OrbitRootCa::pairIsValid($pair['certificate'], $pair['key'], time()))->toBeFalse();
});

it('rejects a private key of another root', function (): void {
    $pair = orbit_root_ca_pair();

    expect(OrbitRootCa::pairIsValid($pair['certificate'], orbit_root_ca_pair('alternate')['key'], time()))->toBeFalse();
});

it('rejects a CA pair whose key is not RSA 4096', function (): void {
    $home = sys_get_temp_dir().'/orbit-root-ca-rule-'.bin2hex(random_bytes(8));
    mkdir($home, 0o700);

    try {
        new Process(['openssl', 'genrsa', '-out', $home.'/root.key', '2048'])->mustRun();
        new Process([
            'openssl', 'req', '-x509', '-new', '-key', $home.'/root.key', '-out', $home.'/root.pem',
            '-days', '1', '-subj', '/CN=Orbit Test Root', '-addext', 'basicConstraints=critical,CA:TRUE',
        ])->mustRun();
        $certificate = (string) file_get_contents($home.'/root.pem');
        $key = (string) file_get_contents($home.'/root.key');

        expect(openssl_x509_check_private_key($certificate, $key))->toBeTrue()
            ->and(OrbitRootCa::pairIsValid($certificate, $key, time()))->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($home);
    }
});

it('rejects a private key that is not PEM', function (): void {
    $pair = orbit_root_ca_pair();

    expect(OrbitRootCa::pairIsValid($pair['certificate'], 'not a key', time()))->toBeFalse();
});

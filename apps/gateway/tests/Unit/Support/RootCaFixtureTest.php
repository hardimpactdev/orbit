<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Support\RootCaFixture;

beforeAll(function (): void {
    RootCaFixture::prepare();
    RootCaFixture::prepare('alternate');
    RootCaFixture::prepare('short-lived', '/CN=Orbit Test Root', 1, ['basicConstraints=critical,CA:TRUE', 'keyUsage=critical,keyCertSign,cRLSign']);
});

it('copies the real RSA-4096 CA into independent private fixture directories', function (): void {
    $home = sys_get_temp_dir().'/orbit-root-ca-copy-'.bin2hex(random_bytes(8));
    mkdir($home.'/first', 0o700, true);
    mkdir($home.'/second', 0o700);

    try {
        RootCaFixture::install($home.'/first');
        RootCaFixture::install($home.'/second');
        $key = file_get_contents($home.'/second/root.key');
        $certificate = file_get_contents($home.'/second/root.pem');
        $privateKey = openssl_pkey_get_private($key);
        expect($privateKey)->not->toBeFalse();
        expect(openssl_pkey_get_details($privateKey)['bits'])->toBe(4096);
        expect(openssl_x509_check_private_key($certificate, $privateKey))->toBeTrue();
        $parsed = openssl_x509_parse($certificate);
        expect($parsed['subject']['CN'])->toBe('Orbit Root CA')
            ->and($parsed['extensions']['basicConstraints'])->toBe('CA:TRUE')
            ->and($parsed['validTo_time_t'] - $parsed['validFrom_time_t'])->toBe(3650 * 86400)
            ->and(fileperms($home.'/second/root.key') & 0o777)->toBe(0o600)
            ->and(fileperms($home.'/second/root.pem') & 0o777)->toBe(0o644);
        file_put_contents($home.'/first/root.key', 'changed first copy');
        file_put_contents($home.'/first/root.pem', 'changed first copy');
        expect(file_get_contents($home.'/second/root.key'))->toBe($key)
            ->and(file_get_contents($home.'/second/root.pem'))->toBe($certificate);
    } finally {
        (new Filesystem)->deleteDirectory($home);
    }
});

it('keeps separately prepared RSA-4096 pairs distinct for mismatched-pair proofs', function (): void {
    $home = sys_get_temp_dir().'/orbit-root-ca-pairs-'.bin2hex(random_bytes(8));
    mkdir($home.'/primary', 0o700, true);
    mkdir($home.'/alternate', 0o700);

    try {
        RootCaFixture::install($home.'/primary');
        RootCaFixture::install($home.'/alternate', 'alternate');
        $key = file_get_contents($home.'/primary/root.key');
        $alternate = file_get_contents($home.'/alternate/root.key');
        $certificate = file_get_contents($home.'/alternate/root.pem');
        expect($key)->not->toBe($alternate)
            ->and(openssl_pkey_get_details(openssl_pkey_get_private($alternate))['bits'])->toBe(4096)
            ->and(openssl_x509_check_private_key($certificate, $alternate))->toBeTrue()
            ->and(openssl_x509_check_private_key($certificate, $key))->toBeFalse();
    } finally {
        (new Filesystem)->deleteDirectory($home);
    }
});

it('preserves explicitly configured subjects lifetimes and critical CA extensions', function (): void {
    $home = sys_get_temp_dir().'/orbit-short-root-ca-'.bin2hex(random_bytes(8));
    mkdir($home, 0o700);

    try {
        RootCaFixture::install($home, 'short-lived');
        $key = file_get_contents($home.'/root.key');
        $certificate = file_get_contents($home.'/root.pem');
        $parsed = openssl_x509_parse($certificate);
        expect($parsed['subject']['CN'])->toBe('Orbit Test Root')
            ->and($parsed['validTo_time_t'] - $parsed['validFrom_time_t'])->toBe(86400)
            ->and($parsed['extensions']['basicConstraints'])->toBe('CA:TRUE')
            ->and($parsed['extensions']['keyUsage'])->toBe('Certificate Sign, CRL Sign')
            ->and(openssl_pkey_get_details(openssl_pkey_get_private($key))['bits'])->toBe(4096)
            ->and(openssl_x509_check_private_key($certificate, $key))->toBeTrue();
        $text = new Process(['openssl', 'x509', '-in', $home.'/root.pem', '-text', '-noout'])->mustRun()->getOutput();
        expect($text)->toContain('X509v3 Basic Constraints: critical', 'X509v3 Key Usage: critical');
    } finally {
        (new Filesystem)->deleteDirectory($home);
    }
});

it('rejects a different configuration for an already prepared name', function (): void {
    expect(fn () => RootCaFixture::prepare(days: 1))
        ->toThrow(RuntimeException::class, 'A named root CA fixture cannot change its configuration.');
});

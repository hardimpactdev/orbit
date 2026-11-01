#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Domain\Certificates\GatewayCertificatePaths;
use App\Infrastructure\Certificates\OpenSslGatewayCertificateValidator;
use App\Infrastructure\Certificates\OrbitLeafCertificate;
use App\Infrastructure\Processes\NativeProcessRunner;
use Symfony\Component\Process\Process;

require '/home/orbit/orbit/apps/gateway/vendor/autoload.php';

umask(0077);
$directory = '/etc/swamp-proof';
$ca = '/home/orbit/.orbit/ca';
$hostname = 'repositories.orbit';
try {
    $address = $argv[1] ?? '';
    if ($argc !== 2 || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || ! str_starts_with($address, '10.232.')) {
        throw new RuntimeException;
    }
    if (is_link($directory) || ! is_dir($directory) && ! mkdir($directory, 0750, true)) {
        throw new RuntimeException;
    }
    $paths = new GatewayCertificatePaths($directory.'/repositories.key', $directory.'/repositories.pem');
    $validator = new OpenSslGatewayCertificateValidator(new NativeProcessRunner);
    if (is_link($paths->privateKeyPath) || is_link($paths->certificatePath)) {
        throw new RuntimeException;
    }
    if (file_exists($paths->privateKeyPath) || file_exists($paths->certificatePath)) {
        if (! is_file($paths->privateKeyPath) || ! is_file($paths->certificatePath)
            || ! $validator->matches($paths, $hostname, $address, $ca.'/root.pem')) {
            throw new RuntimeException;
        }
    } else {
        $candidate = $directory.'/.certificate-candidate';
        if (! mkdir($candidate, 0700)) {
            throw new RuntimeException;
        }
        try {
            file_put_contents($candidate.'/extensions', OrbitLeafCertificate::extensions('fixture', 'DNS:'.$hostname.',IP:'.$address));
            foreach ([
                ['openssl', 'genrsa', '-out', $candidate.'/key', '2048'],
                ['openssl', 'req', '-new', '-key', $candidate.'/key', '-out', $candidate.'/csr', '-subj', '/CN='.$hostname],
                ['openssl', 'x509', '-req', '-in', $candidate.'/csr', '-CA', $ca.'/root.pem', '-CAkey', $ca.'/root.key',
                    '-set_serial', '0x'.bin2hex(random_bytes(16)), '-out', $candidate.'/pem', '-days', (string) OrbitLeafCertificate::ValidityDays,
                    '-sha256', '-extfile', $candidate.'/extensions', '-extensions', 'fixture'],
            ] as $command) {
                $process = new Process($command, timeout: 60);
                if ($process->run() !== 0) {
                    throw new RuntimeException;
                }
            }
            if (! $validator->matches(new GatewayCertificatePaths($candidate.'/key', $candidate.'/pem'), $hostname, $address, $ca.'/root.pem')) {
                throw new RuntimeException;
            }
            if (! rename($candidate.'/key', $paths->privateKeyPath) || ! rename($candidate.'/pem', $paths->certificatePath)) {
                throw new RuntimeException;
            }
        } finally {
            foreach (glob($candidate.'/*') ?: [] as $path) {
                unlink($path);
            }
            rmdir($candidate);
        }
    }
    echo "{\"certificate\":true}\n";
} catch (Throwable) {
    fwrite(STDERR, "e2e.fixture_repository_certificate_failed\n");
    exit(1);
}

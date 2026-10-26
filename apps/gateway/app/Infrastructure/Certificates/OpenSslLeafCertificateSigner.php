<?php

declare(strict_types=1);

namespace App\Infrastructure\Certificates;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use Closure;
use RuntimeException;

final readonly class OpenSslLeafCertificateSigner implements LeafCertificateSigner
{
    /** @var Closure(): int */
    private Closure $clock;

    /** @param null|(Closure(): int) $clock */
    public function __construct(
        private ProcessRunner $processes,
        private string $orbitHome,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? time(...);
    }

    public function sign(string $hostname, string $certificateRequest): string
    {
        if (filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new RuntimeException("Certificate hostname [{$hostname}] is invalid.");
        }

        $this->rootCertificate();

        $validation = $this->processes->run(new ProcessInvocation(
            arguments: ['openssl', 'req', '-in', '/dev/stdin', '-noout', '-verify'],
            timeout: 60.0,
            input: $certificateRequest,
        ));

        if (! $validation->succeeded() || ! $this->hasRsa2048PublicKey($certificateRequest)) {
            throw new RuntimeConvergenceException(
                step: 'certificate-request-validate',
                errorCode: 'app-dev.certificate_request_invalid',
                message: "Certificate request for [{$hostname}] is invalid.",
                result: $validation,
            );
        }

        $extensions = tempnam(directory: sys_get_temp_dir(), prefix: 'orbit-leaf-');

        if (! is_string($extensions)) {
            throw new RuntimeException('Could not create a leaf certificate extension file.');
        }

        try {
            $this->writeExtensions($extensions, $hostname);
            $caDirectory = rtrim(string: $this->orbitHome, characters: '/').'/ca';
            $result = $this->processes->run(new ProcessInvocation(
                arguments: [
                    'openssl',
                    'x509',
                    '-req',
                    '-in',
                    '/dev/stdin',
                    '-CA',
                    $caDirectory.'/root.pem',
                    '-CAkey',
                    $caDirectory.'/root.key',
                    '-set_serial',
                    '0x'.bin2hex(random_bytes(20)),
                    '-days',
                    (string) OrbitLeafCertificate::ValidityDays,
                    '-sha256',
                    '-extfile',
                    $extensions,
                    '-extensions',
                    'leaf',
                ],
                timeout: 60.0,
                input: $certificateRequest,
            ));
        } finally {
            unlink($extensions);
        }

        if (! $result->succeeded() || ! str_contains($result->stdout, 'BEGIN CERTIFICATE')) {
            throw new RuntimeConvergenceException(
                step: 'certificate-sign',
                errorCode: 'app-dev.certificate_sign_failed',
                message: "Could not sign the certificate for [{$hostname}].",
                result: $result,
            );
        }

        return $result->stdout;
    }

    public function rootCertificate(): string
    {
        $directory = rtrim(string: $this->orbitHome, characters: '/').'/ca';
        $lockPath = $directory.'/root.lock';
        $lock = fopen(filename: $lockPath, mode: 'c+');

        if ($lock === false) {
            throw new RuntimeException('Orbit root CA material is not readable.');
        }

        try {
            chmod(filename: $lockPath, permissions: 0o600);

            if (! flock($lock, LOCK_SH)) {
                throw new RuntimeException('Orbit root CA material is not readable.');
            }

            return $this->readRootCertificate($directory);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function readRootCertificate(string $directory): string
    {
        $certificatePath = $directory.'/root.pem';
        $privateKeyPath = $directory.'/root.key';
        $certificateExists = is_file($certificatePath);
        $privateKeyExists = is_file($privateKeyPath);

        if ($certificateExists !== $privateKeyExists) {
            throw new RuntimeException('Orbit root CA state is partial; restore the missing root file.');
        }

        if (! $certificateExists) {
            throw new RuntimeException('Orbit root CA is not bootstrapped.');
        }

        $certificate = file_get_contents($certificatePath);
        $privateKey = file_get_contents($privateKeyPath);

        if (! is_string($certificate) || ! is_string($privateKey)) {
            throw new RuntimeException('Orbit root CA material is not readable.');
        }

        if (! OrbitRootCa::pairIsValid($certificate, $privateKey, ($this->clock)())) {
            throw new RuntimeException('Orbit root CA material is invalid.');
        }

        return $certificate;
    }

    private function writeExtensions(string $path, string $hostname): void
    {
        if (file_put_contents($path, OrbitLeafCertificate::extensions('leaf', "DNS:{$hostname}")) === false) {
            throw new RuntimeException('Could not write leaf certificate extensions.');
        }

        chmod(filename: $path, permissions: 0o600);
    }

    private function hasRsa2048PublicKey(string $certificateRequest): bool
    {
        $publicKey = openssl_csr_get_public_key($certificateRequest);

        if ($publicKey === false) {
            return false;
        }

        $details = openssl_pkey_get_details($publicKey);

        return
            is_array($details)
            && ($details['type'] ?? null) === OPENSSL_KEYTYPE_RSA
            && ($details['bits'] ?? null) === 2048;
    }
}

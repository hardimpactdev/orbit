<?php

declare(strict_types=1);

namespace App\Infrastructure\Certificates;

use App\Domain\Certificates\LeafCertificateSigner;
use Closure;
use RuntimeException;

/** Public trust data for admission and API reads. The Gateway alone retains CA signing custody. */
final readonly class FilesystemRootCertificate implements LeafCertificateSigner
{
    /** @var Closure(): int */
    private Closure $clock;

    /** @param null|Closure(): int $clock */
    public function __construct(private string $orbitHome, ?Closure $clock = null)
    {
        $this->clock = $clock ?? time(...);
    }

    public function rootCertificate(): string
    {
        $path = rtrim($this->orbitHome, '/').'/ca/root.pem';
        $certificate = is_file($path) ? file_get_contents($path) : false;
        $parsed = is_string($certificate) ? @openssl_x509_parse($certificate) : false;
        $public = is_string($certificate) ? @openssl_pkey_get_public($certificate) : false;
        $key = $public === false ? false : openssl_pkey_get_details($public);
        $now = ($this->clock)();
        if (! is_string($certificate) || ! is_array($parsed) || ! is_array($key)
            || ($key['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || ($key['bits'] ?? null) !== 4096
            || ! str_contains($parsed['extensions']['basicConstraints'] ?? '', 'CA:TRUE')
            || ($parsed['validFrom_time_t'] ?? PHP_INT_MAX) > $now || ($parsed['validTo_time_t'] ?? 0) < $now) {
            throw new RuntimeException('Orbit public root CA is unavailable.');
        }

        return $certificate;
    }
}

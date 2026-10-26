<?php

declare(strict_types=1);

namespace App\Infrastructure\Certificates;

/**
 * The rule for a usable Orbit root CA pair. Gateway bootstrap applies it before it keeps or publishes
 * a root, and the leaf signer applies it before it signs with one.
 */
final readonly class OrbitRootCa
{
    /**
     * Whether the PEM pair is an RSA 4096 private key that matches a CA:TRUE certificate valid at $now.
     */
    public static function pairIsValid(string $certificate, string $privateKey, int $now): bool
    {
        $parsedCertificate = openssl_x509_read(certificate: $certificate);
        $parsedPrivateKey = openssl_pkey_get_private($privateKey);
        $privateKeyDetails = $parsedPrivateKey !== false
            ? openssl_pkey_get_details($parsedPrivateKey)
            : false;
        $details = $parsedCertificate !== false ? openssl_x509_parse($parsedCertificate) : false;
        $basicConstraints = is_array($details)
            ? $details['extensions']['basicConstraints'] ?? null
            : null;
        $validFrom = is_array($details) ? $details['validFrom_time_t'] : null;
        $validTo = is_array($details) ? $details['validTo_time_t'] : null;

        return
            $parsedCertificate !== false
            && $parsedPrivateKey !== false
            && is_array($privateKeyDetails)
            && ($privateKeyDetails['type'] ?? null) === OPENSSL_KEYTYPE_RSA
            && ($privateKeyDetails['bits'] ?? null) === 4096
            && is_string($basicConstraints)
            && str_contains($basicConstraints, 'CA:TRUE')
            && is_int($validFrom)
            && $validFrom <= $now
            && is_int($validTo)
            && $validTo >= $now
            && openssl_x509_check_private_key($parsedCertificate, $parsedPrivateKey);
    }
}

<?php

declare(strict_types=1);

namespace App\Infrastructure\Certificates;

/**
 * The lifetime and extensions of every leaf the Orbit root CA signs: the Gateway's own leaves and
 * the development leaves of Nodes. The agent's `certificate.request-leaf` reuses a current leaf
 * only while it still carries this policy.
 */
final readonly class OrbitLeafCertificate
{
    public const int ValidityDays = 397;

    /**
     * The OpenSSL extension section `$section` for a TLS server leaf with the given subject alternative names.
     */
    public static function extensions(string $section, string $subjectAltName): string
    {
        return <<<EXTENSIONS
            [{$section}]
            basicConstraints = critical,CA:FALSE
            keyUsage = critical,digitalSignature,keyEncipherment
            extendedKeyUsage = serverAuth
            subjectAltName = {$subjectAltName}
            EXTENSIONS;
    }
}

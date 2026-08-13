<?php

declare(strict_types=1);

namespace App\Services\Proxy;

final readonly class ProxyCertificateValidity
{
    public function days(string $encodedCertificate): ?int
    {
        if ($encodedCertificate === '') {
            return null;
        }

        $certificate = base64_decode(string: $encodedCertificate, strict: true);

        if (! is_string($certificate)) {
            return null;
        }

        $parsed = openssl_x509_parse(certificate: $certificate, short_names: false);

        if (! is_array($parsed)) {
            return null;
        }

        $validityRange = $this->validityRange(
            $parsed['validFrom_time_t'] ?? null,
            $parsed['validTo_time_t'] ?? null,
        );

        if ($validityRange === null) {
            return null;
        }

        [$startsAt, $expiresAt] = $validityRange;

        return intdiv(num1: $expiresAt - $startsAt, num2: 86_400);
    }

    /**
     * @return array{int, int}|null
     */
    private function validityRange(mixed $startsAt, mixed $expiresAt): ?array
    {
        if (! is_int($startsAt) || ! is_int($expiresAt) || $expiresAt < $startsAt) {
            return null;
        }

        return [$startsAt, $expiresAt];
    }
}

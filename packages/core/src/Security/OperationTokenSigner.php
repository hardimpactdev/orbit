<?php

declare(strict_types=1);

namespace Orbit\Core\Security;

final class OperationTokenSigner
{
    public function sign(
        string $secret,
        string $id,
        string $node,
        string $command,
        int $issuedAt,
        int $expiresAt,
    ): OperationToken {
        $payload = $this->canonicalPayload(
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        );

        $signature = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $payload, $secret, true),
        ), '+/', '-_'), '=');

        return new OperationToken(
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            signature: $signature,
        );
    }

    private function canonicalPayload(
        string $id,
        string $node,
        string $command,
        int $issuedAt,
        int $expiresAt,
    ): string {
        // Canonical payload form: each field is encoded as a 32-bit
        // big-endian byte length followed by the exact field bytes.
        return $this->lengthPrefixed($id)
            .$this->lengthPrefixed($node)
            .$this->lengthPrefixed($command)
            .$this->lengthPrefixed((string) $issuedAt)
            .$this->lengthPrefixed((string) $expiresAt);
    }

    private function lengthPrefixed(string $value): string
    {
        return pack('N', strlen($value)).$value;
    }
}

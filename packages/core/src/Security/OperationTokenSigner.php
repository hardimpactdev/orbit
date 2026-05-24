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
        $payload = implode("\0", [
            $id,
            $node,
            $command,
            (string) $issuedAt,
            (string) $expiresAt,
        ]);

        $signature = rtrim(strtr(base64_encode(
            hash_hmac('sha256', $payload, $secret, true),
        ), '+/', '-_'), '=');

        return new OperationToken(
            id: $id,
            node: $node,
            command: $command,
            issued_at: $issuedAt,
            expires_at: $expiresAt,
            signature: $signature,
        );
    }
}

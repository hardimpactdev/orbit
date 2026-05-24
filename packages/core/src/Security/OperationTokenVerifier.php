<?php

declare(strict_types=1);

namespace Orbit\Core\Security;

final class OperationTokenVerifier
{
    public function verify(
        string $secret,
        OperationToken $token,
        string $expectedNode,
        string $expectedCommand,
        ?int $now = null,
    ): bool {
        $expectedToken = (new OperationTokenSigner)->sign(
            secret: $secret,
            id: $token->id,
            node: $token->node,
            command: $token->command,
            issuedAt: $token->issued_at,
            expiresAt: $token->expires_at,
        );

        $signatureMatches = hash_equals($expectedToken->signature, $token->signature);
        $nodeMatches = hash_equals($expectedNode, $token->node);
        $commandMatches = hash_equals($expectedCommand, $token->command);
        $isNotExpired = ($now ?? time()) <= $token->expires_at;

        return $signatureMatches
            && $nodeMatches
            && $commandMatches
            && $isNotExpired;
    }
}

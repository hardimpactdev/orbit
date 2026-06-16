<?php

declare(strict_types=1);

namespace Orbit\Core\Security;

final readonly class OperationTokenVerifier
{
    public function __construct(
        private OperationTokenSigner $signer,
    ) {}

    public function verify(
        string $secret,
        OperationToken $token,
        string $expectedNode,
        string $expectedCommand,
        ?int $now = null,
    ): bool {
        $expectedToken = $this->signer->sign(
            secret: $secret,
            id: $token->id,
            node: $token->node,
            command: $token->command,
            issuedAt: $token->issuedAt,
            expiresAt: $token->expiresAt,
        );

        $signatureMatches = hash_equals($expectedToken->signature, $token->signature);
        $nodeMatches = hash_equals($expectedNode, $token->node);
        $commandMatches = hash_equals($expectedCommand, $token->command);
        $isNotExpired = ($now ?? time()) <= $token->expiresAt;

        return $signatureMatches
            && $nodeMatches
            && $commandMatches
            && $isNotExpired;
    }
}

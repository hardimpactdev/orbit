<?php

declare(strict_types=1);

namespace Orbit\Core\Security;

final readonly class OperationTokenVerifier
{
    public function __construct(
        private OperationTokenSigner $signer,
    ) {}

    /**
     * @mago-expect lint:excessive-parameter-list
     */
    public function verify(
        array $secretsByKeyId,
        OperationToken $token,
        string $expectedNode,
        string $expectedCommand,
        string $expectedCommandContextHash,
        ?int $now = null,
        int $notBeforeSkewSeconds = 5,
    ): bool {
        $secret = $secretsByKeyId[$token->keyId] ?? null;

        if (! is_string($secret) || trim($secret) === '') {
            return false;
        }

        $expectedToken = $this->signer->sign(
            secret: $secret,
            keyId: $token->keyId,
            id: $token->id,
            node: $token->node,
            command: $token->command,
            commandContextHash: $token->commandContextHash,
            issuedAt: $token->issuedAt,
            expiresAt: $token->expiresAt,
        );

        $signatureMatches = hash_equals($expectedToken->signature, $token->signature);
        $nodeMatches = hash_equals($expectedNode, $token->node);
        $commandMatches = hash_equals($expectedCommand, $token->command);
        $commandContextMatches = hash_equals($expectedCommandContextHash, $token->commandContextHash);
        $currentTimestamp = $now ?? time();
        $isNotExpired = $currentTimestamp <= $token->expiresAt;
        $isNotBefore = ($currentTimestamp + max(0, $notBeforeSkewSeconds)) >= $token->issuedAt;

        return (
            $signatureMatches
            && $nodeMatches
            && $commandMatches
            && $commandContextMatches
            && $isNotExpired
            && $isNotBefore
        );
    }
}

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
        string $keyId = 'current',
        ?string $commandContextHash = null,
    ): OperationToken {
        $commandContextHash ??= OperationTokenCommandContext::fromTrustedDispatch(
            argv: [
                $command,
                '--operation-token='.OperationTokenCommandContext::OPERATION_TOKEN_SENTINEL,
            ],
            cwd: null,
            environment: [],
            input: null,
        )->hash();

        $payload = $this->canonicalPayload(
            id: $id,
            node: $node,
            command: $command,
            keyId: $keyId,
            commandContextHash: $commandContextHash,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        );

        $signature = rtrim(
            strtr(
                base64_encode(
                    hash_hmac('sha256', $payload, $secret, true),
                ),
                '+/',
                '-_',
            ),
            '=',
        );

        return new OperationToken(
            id: $id,
            node: $node,
            command: $command,
            keyId: $keyId,
            commandContextHash: $commandContextHash,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            signature: $signature,
        );
    }

    /**
     * @mago-expect lint:excessive-parameter-list
     */
    private function canonicalPayload(
        string $id,
        string $node,
        string $command,
        string $keyId,
        string $commandContextHash,
        int $issuedAt,
        int $expiresAt,
    ): string {
        // Canonical payload form: each field is encoded as a 32-bit
        // big-endian byte length followed by the exact field bytes.
        return (
            $this->lengthPrefixed($id)
            .$this->lengthPrefixed($node)
            .$this->lengthPrefixed($command)
            .$this->lengthPrefixed($keyId)
            .$this->lengthPrefixed($commandContextHash)
            .$this->lengthPrefixed((string) $issuedAt)
            .$this->lengthPrefixed((string) $expiresAt)
        );
    }

    private function lengthPrefixed(string $value): string
    {
        return pack('N', strlen($value)).$value;
    }
}

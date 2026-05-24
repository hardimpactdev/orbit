<?php

declare(strict_types=1);

use Orbit\Core\Security\OperationToken;
use Orbit\Core\Security\OperationTokenSigner;
use Orbit\Core\Security\OperationTokenVerifier;

describe(OperationToken::class, function (): void {
    it('constructs with readable fields', function (): void {
        $token = new OperationToken(
            id: '018fded1-f2f4-72c4-9c33-62978d6f26f5',
            node: 'app-dev',
            command: 'internal:workspace-adapter',
            issued_at: 1_798_105_200,
            expires_at: 1_798_105_320,
            signature: 'c2lnbmF0dXJl',
        );

        expect($token->id)->toBe('018fded1-f2f4-72c4-9c33-62978d6f26f5')
            ->and($token->node)->toBe('app-dev')
            ->and($token->command)->toBe('internal:workspace-adapter')
            ->and($token->issued_at)->toBe(1_798_105_200)
            ->and($token->expires_at)->toBe(1_798_105_320)
            ->and($token->signature)->toBe('c2lnbmF0dXJl');
    });

    it('serializes to a compact string and parses back to the same fields', function (): void {
        $token = (new OperationTokenSigner)->sign(
            secret: 'gateway-secret',
            id: '018fded1-f2f4-72c4-9c33-62978d6f26f5',
            node: 'app-dev',
            command: 'internal:workspace-adapter',
            issuedAt: 1_798_105_200,
            expiresAt: 1_798_105_320,
        );

        $parsed = OperationToken::parse($token->toString());

        expect($parsed)->toEqual($token);
    });

    it('rejects malformed compact strings', function (string $compact): void {
        expect(fn () => OperationToken::parse($compact))->toThrow(InvalidArgumentException::class);
    })->with([
        'empty' => '',
        'garbage' => 'not-a-token',
        'wrong segment count' => 'a.b.c',
    ]);
});

describe(OperationTokenSigner::class, function (): void {
    it('produces deterministic signatures for the same inputs', function (): void {
        $signer = new OperationTokenSigner;

        $first = $signer->sign(
            secret: 'gateway-secret',
            id: '018fded1-f2f4-72c4-9c33-62978d6f26f5',
            node: 'app-dev',
            command: 'internal:workspace-adapter',
            issuedAt: 1_798_105_200,
            expiresAt: 1_798_105_320,
        );

        $second = $signer->sign(
            secret: 'gateway-secret',
            id: '018fded1-f2f4-72c4-9c33-62978d6f26f5',
            node: 'app-dev',
            command: 'internal:workspace-adapter',
            issuedAt: 1_798_105_200,
            expiresAt: 1_798_105_320,
        );

        expect($second->signature)->toBe($first->signature);
    });
});

describe(OperationTokenVerifier::class, function (): void {
    it('accepts a valid token for the expected node and command before expiry', function (): void {
        $token = validOperationToken();

        expect((new OperationTokenVerifier)->verify(
            secret: 'gateway-secret',
            token: $token,
            expectedNode: 'app-dev',
            expectedCommand: 'internal:workspace-adapter',
            now: 1_798_105_260,
        ))->toBeTrue();
    });

    it('rejects tokens signed with the wrong secret', function (): void {
        $token = validOperationToken();

        expect((new OperationTokenVerifier)->verify(
            secret: 'wrong-secret',
            token: $token,
            expectedNode: 'app-dev',
            expectedCommand: 'internal:workspace-adapter',
            now: 1_798_105_260,
        ))->toBeFalse();
    });

    it('rejects tokens for the wrong node', function (): void {
        $token = validOperationToken();

        expect((new OperationTokenVerifier)->verify(
            secret: 'gateway-secret',
            token: $token,
            expectedNode: 'app-prod',
            expectedCommand: 'internal:workspace-adapter',
            now: 1_798_105_260,
        ))->toBeFalse();
    });

    it('rejects tokens for the wrong command', function (): void {
        $token = validOperationToken();

        expect((new OperationTokenVerifier)->verify(
            secret: 'gateway-secret',
            token: $token,
            expectedNode: 'app-dev',
            expectedCommand: 'internal:wg-easy',
            now: 1_798_105_260,
        ))->toBeFalse();
    });

    it('rejects expired tokens', function (): void {
        $token = validOperationToken();

        expect((new OperationTokenVerifier)->verify(
            secret: 'gateway-secret',
            token: $token,
            expectedNode: 'app-dev',
            expectedCommand: 'internal:workspace-adapter',
            now: 1_798_105_321,
        ))->toBeFalse();
    });

    it('rejects tampered payloads that were not resigned', function (): void {
        $token = validOperationToken();

        $tampered = new OperationToken(
            id: $token->id,
            node: 'app-prod',
            command: $token->command,
            issued_at: $token->issued_at,
            expires_at: $token->expires_at,
            signature: $token->signature,
        );

        expect((new OperationTokenVerifier)->verify(
            secret: 'gateway-secret',
            token: $tampered,
            expectedNode: 'app-prod',
            expectedCommand: 'internal:workspace-adapter',
            now: 1_798_105_260,
        ))->toBeFalse();
    });

    it('uses timing-safe string comparisons for verifier checks', function (): void {
        $token = validOperationToken();

        // This regression test documents the security contract: verifier string
        // comparisons must use hash_equals(), not direct equality checks.
        expect((new OperationTokenVerifier)->verify(
            secret: 'gateway-secret',
            token: $token,
            expectedNode: 'app-dev',
            expectedCommand: 'internal:workspace-adapter',
            now: 1_798_105_260,
        ))->toBeTrue();
    });
});

function validOperationToken(): OperationToken
{
    return (new OperationTokenSigner)->sign(
        secret: 'gateway-secret',
        id: '018fded1-f2f4-72c4-9c33-62978d6f26f5',
        node: 'app-dev',
        command: 'internal:workspace-adapter',
        issuedAt: 1_798_105_200,
        expiresAt: 1_798_105_320,
    );
}

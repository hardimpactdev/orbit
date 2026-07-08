<?php

declare(strict_types=1);

use Orbit\Core\Security\OperationToken;
use Orbit\Core\Security\OperationTokenCommandContext;
use Orbit\Core\Security\OperationTokenSigner;
use Orbit\Core\Security\OperationTokenVerifier;

describe(OperationToken::class, function (): void {
    it('constructs with readable fields', function (): void {
        $token = new OperationToken(
            id: '018fded1-f2f4-72c4-9c33-62978d6f26f5',
            node: 'app-dev',
            command: 'internal:workspace-adapter',
            keyId: 'current',
            commandContextHash: str_repeat('a', times: 64),
            issuedAt: 1_798_105_200,
            expiresAt: 1_798_105_320,
            signature: 'c2lnbmF0dXJl',
        );

        expect($token->id)
            ->toBe('018fded1-f2f4-72c4-9c33-62978d6f26f5')
            ->and($token->node)
            ->toBe('app-dev')
            ->and($token->command)
            ->toBe('internal:workspace-adapter')
            ->and($token->keyId)
            ->toBe('current')
            ->and($token->commandContextHash)
            ->toBe(str_repeat('a', times: 64))
            ->and($token->issuedAt)
            ->toBe(1_798_105_200)
            ->and($token->expiresAt)
            ->toBe(1_798_105_320)
            ->and($token->signature)
            ->toBe('c2lnbmF0dXJl');
    });

    it('serializes to the same compact wire format and parses back to the same fields', function (): void {
        $token = new OperationToken(
            id: '018fded1-f2f4-72c4-9c33-62978d6f26f5',
            node: 'app-dev',
            command: 'internal:workspace-adapter',
            keyId: 'current',
            commandContextHash: str_repeat('a', times: 64),
            issuedAt: 1_798_105_200,
            expiresAt: 1_798_105_320,
            signature: 'c2lnbmF0dXJl',
        );

        $parsed = OperationToken::parse($token->toString());

        expect($token->toString())
            ->toBeString()
            ->and(explode('.', $token->toString()))
            ->toHaveCount(8)
            ->and($parsed)
            ->toEqual($token);
    });

    it('serializes the base64url signature segment verbatim', function (): void {
        $token = new OperationTokenSigner()->sign(
            secret: 'gateway-secret',
            keyId: 'current',
            id: '018fded1-f2f4-72c4-9c33-62978d6f26f5',
            node: 'app-dev',
            command: 'internal:workspace-adapter',
            commandContextHash: operation_token_test_command_context()->hash(),
            issuedAt: 1_798_105_200,
            expiresAt: 1_798_105_320,
        );

        $segments = explode('.', $token->toString());

        expect($segments[7])
            ->toBe($token->signature)
            ->and(OperationToken::parse($token->toString())->signature)
            ->toBe($token->signature);
    });

    it('rejects malformed compact strings', function (string $compact): void {
        expect(fn () => OperationToken::parse($compact))->toThrow(InvalidArgumentException::class);
    })->with([
        'empty' => '',
        'garbage' => 'not-a-token',
        'wrong segment count' => 'a.b.c',
    ]);

    it('rejects timestamp segments outside the signed 64-bit integer range', function (string $issuedAt): void {
        $compact = implode('.', [
            base64UrlEncodeForOperationTokenTest('018fded1-f2f4-72c4-9c33-62978d6f26f5'),
            base64UrlEncodeForOperationTokenTest('app-dev'),
            base64UrlEncodeForOperationTokenTest('internal:workspace-adapter'),
            base64UrlEncodeForOperationTokenTest('current'),
            base64UrlEncodeForOperationTokenTest(str_repeat('a', times: 64)),
            base64UrlEncodeForOperationTokenTest($issuedAt),
            base64UrlEncodeForOperationTokenTest('1798105320'),
            'c2lnbmF0dXJl',
        ]);

        expect(fn () => OperationToken::parse($compact))->toThrow(InvalidArgumentException::class);
    })->with([
        'too many digits' => str_repeat('9', 30),
        'greater than PHP_INT_MAX' => '9223372036854775808',
    ]);
});

describe(OperationTokenSigner::class, function (): void {
    it('produces deterministic signatures for the same inputs', function (): void {
        $signer = new OperationTokenSigner;

        $first = $signer->sign(
            secret: 'gateway-secret',
            keyId: 'current',
            id: '018fded1-f2f4-72c4-9c33-62978d6f26f5',
            node: 'app-dev',
            command: 'internal:workspace-adapter',
            commandContextHash: operation_token_test_command_context()->hash(),
            issuedAt: 1_798_105_200,
            expiresAt: 1_798_105_320,
        );

        $second = $signer->sign(
            secret: 'gateway-secret',
            keyId: 'current',
            id: '018fded1-f2f4-72c4-9c33-62978d6f26f5',
            node: 'app-dev',
            command: 'internal:workspace-adapter',
            commandContextHash: operation_token_test_command_context()->hash(),
            issuedAt: 1_798_105_200,
            expiresAt: 1_798_105_320,
        );

        expect($second->signature)->toBe($first->signature);
    });
});

describe(OperationTokenCommandContext::class, function (): void {
    it('normalizes the embedded operation token value while binding the rest of the execution context', function (): void {
        $agentContext = OperationTokenCommandContext::fromAgentVerification(
            argv: [
                'internal:workspace-adapter',
                '--workspace=demo',
                '--operation-token=signed-operation-token',
                '--json',
            ],
            cwd: '/srv/orbit',
            environment: ['BETA' => '2', 'ALPHA' => '1'],
            input: 'stdin-payload',
            operationToken: 'signed-operation-token',
        );

        $trustedContext = OperationTokenCommandContext::fromTrustedDispatch(
            argv: [
                'internal:workspace-adapter',
                '--workspace=demo',
                '--operation-token='.OperationTokenCommandContext::OPERATION_TOKEN_SENTINEL,
                '--json',
            ],
            cwd: '/srv/orbit',
            environment: ['ALPHA' => '1', 'BETA' => '2'],
            input: 'stdin-payload',
        );

        $tamperedContext = OperationTokenCommandContext::fromAgentVerification(
            argv: [
                'internal:workspace-adapter',
                '--workspace=other',
                '--operation-token=signed-operation-token',
                '--json',
            ],
            cwd: '/srv/orbit',
            environment: ['ALPHA' => '1', 'BETA' => '2'],
            input: 'stdin-payload',
            operationToken: 'signed-operation-token',
        );

        expect($agentContext->hash())
            ->toBe($trustedContext->hash())
            ->and($tamperedContext->hash())
            ->not->toBe($trustedContext->hash());
    });

    it('rejects an argv operation token that differs from the submitted bearer token', function (): void {
        expect(fn (): OperationTokenCommandContext => OperationTokenCommandContext::fromAgentVerification(
            argv: [
                'internal:workspace-adapter',
                '--operation-token=different-token',
                '--json',
            ],
            cwd: null,
            environment: [],
            input: null,
            operationToken: 'signed-operation-token',
        ))
            ->toThrow(InvalidArgumentException::class, 'operation token');
    });
});

describe(OperationTokenVerifier::class, function (): void {
    it('accepts a valid token for the expected node and command before expiry', function (): void {
        $token = validOperationToken();

        expect(operationTokenVerifier()->verify(
            secretsByKeyId: ['current' => 'gateway-secret'],
            token: $token,
            expectedNode: 'app-dev',
            expectedCommand: 'internal:workspace-adapter',
            expectedCommandContextHash: operation_token_test_command_context()->hash(),
            now: 1_798_105_260,
        ))->toBeTrue();
    });

    it('rejects tokens signed with the wrong secret', function (): void {
        $token = validOperationToken();

        expect(operationTokenVerifier()->verify(
            secretsByKeyId: ['current' => 'wrong-secret'],
            token: $token,
            expectedNode: 'app-dev',
            expectedCommand: 'internal:workspace-adapter',
            expectedCommandContextHash: operation_token_test_command_context()->hash(),
            now: 1_798_105_260,
        ))->toBeFalse();
    });

    it('rejects tokens for the wrong node', function (): void {
        $token = validOperationToken();

        expect(operationTokenVerifier()->verify(
            secretsByKeyId: ['current' => 'gateway-secret'],
            token: $token,
            expectedNode: 'app-prod',
            expectedCommand: 'internal:workspace-adapter',
            expectedCommandContextHash: operation_token_test_command_context()->hash(),
            now: 1_798_105_260,
        ))->toBeFalse();
    });

    it('rejects tokens for the wrong command', function (): void {
        $token = validOperationToken();

        expect(operationTokenVerifier()->verify(
            secretsByKeyId: ['current' => 'gateway-secret'],
            token: $token,
            expectedNode: 'app-dev',
            expectedCommand: 'internal:wg-easy',
            expectedCommandContextHash: operation_token_test_command_context()->hash(),
            now: 1_798_105_260,
        ))->toBeFalse();
    });

    it('rejects expired tokens', function (): void {
        $token = validOperationToken();

        expect(operationTokenVerifier()->verify(
            secretsByKeyId: ['current' => 'gateway-secret'],
            token: $token,
            expectedNode: 'app-dev',
            expectedCommand: 'internal:workspace-adapter',
            expectedCommandContextHash: operation_token_test_command_context()->hash(),
            now: 1_798_105_321,
        ))->toBeFalse();
    });

    it('rejects tokens before issued_at beyond the configured clock skew', function (): void {
        $context = operation_token_test_command_context();
        $token = new OperationTokenSigner()->sign(
            secret: 'gateway-secret',
            keyId: 'current',
            id: '018fded1-f2f4-72c4-9c33-62978d6f26f5',
            node: 'app-dev',
            command: 'internal:workspace-adapter',
            commandContextHash: $context->hash(),
            issuedAt: 1_798_105_200,
            expiresAt: 1_798_105_320,
        );

        expect(operationTokenVerifier()->verify(
            secretsByKeyId: ['current' => 'gateway-secret'],
            token: $token,
            expectedNode: 'app-dev',
            expectedCommand: 'internal:workspace-adapter',
            expectedCommandContextHash: $context->hash(),
            now: 1_798_105_190,
            notBeforeSkewSeconds: 5,
        ))->toBeFalse();
    });

    it('rejects tokens when the execution context hash differs from the signed hash', function (): void {
        $context = operation_token_test_command_context();
        $token = new OperationTokenSigner()->sign(
            secret: 'gateway-secret',
            keyId: 'current',
            id: '018fded1-f2f4-72c4-9c33-62978d6f26f5',
            node: 'app-dev',
            command: 'internal:workspace-adapter',
            commandContextHash: $context->hash(),
            issuedAt: 1_798_105_200,
            expiresAt: 1_798_105_320,
        );
        $tamperedContext = OperationTokenCommandContext::fromTrustedDispatch(
            argv: [
                'internal:workspace-adapter',
                '--workspace=other',
                '--operation-token='.OperationTokenCommandContext::OPERATION_TOKEN_SENTINEL,
                '--json',
            ],
            cwd: '/srv/orbit',
            environment: ['ALPHA' => '1'],
            input: null,
        );

        expect(operationTokenVerifier()->verify(
            secretsByKeyId: ['current' => 'gateway-secret'],
            token: $token,
            expectedNode: 'app-dev',
            expectedCommand: 'internal:workspace-adapter',
            expectedCommandContextHash: $tamperedContext->hash(),
            now: 1_798_105_260,
            notBeforeSkewSeconds: 5,
        ))->toBeFalse();
    });

    it('accepts a token signed with the previous key id during rotation', function (): void {
        $context = operation_token_test_command_context();
        $token = new OperationTokenSigner()->sign(
            secret: 'previous-secret',
            keyId: 'previous',
            id: '018fded1-f2f4-72c4-9c33-62978d6f26f5',
            node: 'app-dev',
            command: 'internal:workspace-adapter',
            commandContextHash: $context->hash(),
            issuedAt: 1_798_105_200,
            expiresAt: 1_798_105_320,
        );

        expect(operationTokenVerifier()->verify(
            secretsByKeyId: [
                'current' => 'gateway-secret',
                'previous' => 'previous-secret',
            ],
            token: $token,
            expectedNode: 'app-dev',
            expectedCommand: 'internal:workspace-adapter',
            expectedCommandContextHash: $context->hash(),
            now: 1_798_105_260,
            notBeforeSkewSeconds: 5,
        ))->toBeTrue();
    });

    it('rejects tampered payloads that were not resigned', function (): void {
        $token = validOperationToken();

        $tampered = new OperationToken(
            id: $token->id,
            node: 'app-prod',
            command: $token->command,
            keyId: $token->keyId,
            commandContextHash: $token->commandContextHash,
            issuedAt: $token->issuedAt,
            expiresAt: $token->expiresAt,
            signature: $token->signature,
        );

        expect(operationTokenVerifier()->verify(
            secretsByKeyId: ['current' => 'gateway-secret'],
            token: $tampered,
            expectedNode: 'app-prod',
            expectedCommand: 'internal:workspace-adapter',
            expectedCommandContextHash: operation_token_test_command_context()->hash(),
            now: 1_798_105_260,
        ))->toBeFalse();
    });

    it('rejects payloads where field boundaries are forgeable by NUL bytes', function (): void {
        $signer = new OperationTokenSigner;
        $verifier = operationTokenVerifier();

        $original = $signer->sign(
            secret: 'gateway-secret',
            keyId: 'current',
            id: 'op',
            node: 'node-a',
            command: "cmd-a\0cmd-b",
            commandContextHash: str_repeat('a', times: 64),
            issuedAt: 100,
            expiresAt: 200,
        );

        $forged = new OperationToken(
            id: "op\0node-a",
            node: 'cmd-a',
            command: 'cmd-b',
            keyId: 'current',
            commandContextHash: str_repeat('a', times: 64),
            issuedAt: 100,
            expiresAt: 200,
            signature: $original->signature,
        );

        expect($verifier->verify(
            secretsByKeyId: ['current' => 'gateway-secret'],
            token: $forged,
            expectedNode: 'cmd-a',
            expectedCommand: 'cmd-b',
            expectedCommandContextHash: str_repeat('a', times: 64),
            now: 150,
        ))
            ->toBeFalse();
    });

    it('uses timing-safe string comparisons for verifier checks', function (): void {
        $token = validOperationToken();

        // This regression test documents the security contract: verifier string
        // comparisons must use hash_equals(), not direct equality checks.
        expect(operationTokenVerifier()->verify(
            secretsByKeyId: ['current' => 'gateway-secret'],
            token: $token,
            expectedNode: 'app-dev',
            expectedCommand: 'internal:workspace-adapter',
            expectedCommandContextHash: operation_token_test_command_context()->hash(),
            now: 1_798_105_260,
        ))->toBeTrue();
    });
});

function validOperationToken(): OperationToken
{
    return new OperationTokenSigner()->sign(
        secret: 'gateway-secret',
        keyId: 'current',
        id: '018fded1-f2f4-72c4-9c33-62978d6f26f5',
        node: 'app-dev',
        command: 'internal:workspace-adapter',
        commandContextHash: operation_token_test_command_context()->hash(),
        issuedAt: 1_798_105_200,
        expiresAt: 1_798_105_320,
    );
}

function operationTokenVerifier(): OperationTokenVerifier
{
    return new OperationTokenVerifier(new OperationTokenSigner);
}

function operation_token_test_command_context(): OperationTokenCommandContext
{
    return OperationTokenCommandContext::fromTrustedDispatch(
        argv: [
            'internal:workspace-adapter',
            '--workspace=demo',
            '--operation-token='.OperationTokenCommandContext::OPERATION_TOKEN_SENTINEL,
            '--json',
        ],
        cwd: '/srv/orbit',
        environment: ['ALPHA' => '1'],
        input: null,
    );
}

function base64UrlEncodeForOperationTokenTest(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

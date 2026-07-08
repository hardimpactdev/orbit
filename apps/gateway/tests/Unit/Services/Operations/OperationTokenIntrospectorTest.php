<?php

declare(strict_types=1);

use App\Services\Operations\OperationTokenIntrospector;
use Orbit\Core\Security\OperationTokenCommandContext;
use Orbit\Core\Security\OperationTokenSigner;
use Orbit\Core\Security\OperationTokenVerifier;
use Tests\TestCase;

uses(TestCase::class);

describe(OperationTokenIntrospector::class, function (): void {
    it('allows a valid token for the authenticated node and command', function (): void {
        $token = operation_token_introspector_test_token(
            id: 'operation-123',
            node: 'app-dev',
            command: 'internal:executor:verify',
        );

        expect(operation_token_introspector_test_introspect(
            compactToken: $token,
            expectedNode: 'app-dev',
            expectedCommand: 'internal:executor:verify',
        ))->toBe([
            'allowed' => true,
            'reason' => null,
            'operation_id' => 'operation-123',
        ]);
    });

    it('rejects malformed tokens', function (): void {
        expect(operation_token_introspector_test_introspect(
            compactToken: 'not-a-token',
            expectedNode: 'app-dev',
            expectedCommand: 'internal:executor:verify',
        ))->toBe([
            'allowed' => false,
            'reason' => 'invalid_token',
            'operation_id' => null,
        ]);
    });

    it('rejects tokens for a different target node', function (): void {
        $token = operation_token_introspector_test_token(
            id: 'operation-123',
            node: 'app-prod',
            command: 'internal:executor:verify',
        );

        expect(operation_token_introspector_test_introspect(
            compactToken: $token,
            expectedNode: 'app-dev',
            expectedCommand: 'internal:executor:verify',
        ))->toBe([
            'allowed' => false,
            'reason' => 'target_node_mismatch',
            'operation_id' => 'operation-123',
        ]);
    });

    it('rejects tokens for a different command', function (): void {
        $token = operation_token_introspector_test_token(
            id: 'operation-123',
            node: 'app-dev',
            command: 'internal:executor:status',
        );

        expect(operation_token_introspector_test_introspect(
            compactToken: $token,
            expectedNode: 'app-dev',
            expectedCommand: 'internal:executor:verify',
        ))->toBe([
            'allowed' => false,
            'reason' => 'command_mismatch',
            'operation_id' => 'operation-123',
        ]);
    });

    it('rejects tokens with invalid signatures', function (): void {
        $token = operation_token_introspector_test_token(
            id: 'operation-123',
            node: 'app-dev',
            command: 'internal:executor:verify',
        );

        $segments = explode('.', $token);
        $segments[0] = operationTokenIntrospectorBase64UrlEncode('operation-tampered');

        expect(operation_token_introspector_test_introspect(
            compactToken: implode('.', $segments),
            expectedNode: 'app-dev',
            expectedCommand: 'internal:executor:verify',
        ))->toBe([
            'allowed' => false,
            'reason' => 'invalid_token',
            'operation_id' => 'operation-tampered',
        ]);
    });

    it('rejects expired tokens', function (): void {
        $token = operation_token_introspector_test_token(
            id: 'operation-123',
            node: 'app-dev',
            command: 'internal:executor:verify',
            issuedAt: 1_798_105_200,
            expiresAt: 1_798_105_201,
        );

        expect(operation_token_introspector_test_introspect(
            compactToken: $token,
            expectedNode: 'app-dev',
            expectedCommand: 'internal:executor:verify',
            introspector: operationTokenIntrospector(now: 1_798_105_202),
        ))->toBe([
            'allowed' => false,
            'reason' => 'invalid_token',
            'operation_id' => 'operation-123',
        ]);
    });

    it('resolves from the app key config through the container', function (): void {
        config()->set('app.key', 'gateway-app-key');
        config()->set('orbit.operation_token_secret', null);
        config()->set('orbit.operation_token_key_id', null);
        config()->set('orbit.operation_token_previous_secret', null);
        config()->set('orbit.operation_token_previous_key_id', null);
        config()->set('orbit.operation_token_ttl_seconds', 120);

        app()->forgetInstance(OperationTokenIntrospector::class);

        $issuedAt = time() - 1;
        $token = operationTokenIntrospectorTestSigner()->sign(
            secret: 'gateway-app-key',
            keyId: 'app-key',
            id: 'operation-123',
            node: 'app-dev',
            command: 'internal:executor:verify',
            commandContextHash: operation_token_introspector_test_context_hash('internal:executor:verify'),
            issuedAt: $issuedAt,
            expiresAt: $issuedAt + 120,
        );

        expect(operation_token_introspector_test_introspect(
            compactToken: $token->toString(),
            expectedNode: 'app-dev',
            expectedCommand: 'internal:executor:verify',
            introspector: app(OperationTokenIntrospector::class),
        ))->toBe([
            'allowed' => true,
            'reason' => null,
            'operation_id' => 'operation-123',
        ]);
    });

    it('throws when the configured app key is missing', function (): void {
        config()->set('app.key', null);
        config()->set('orbit.operation_token_ttl_seconds', 120);

        app()->forgetInstance(OperationTokenIntrospector::class);

        expect(fn (): OperationTokenIntrospector => app(OperationTokenIntrospector::class))
            ->toThrow(RuntimeException::class);
    });
});

function operationTokenIntrospector(?int $now = 1_798_105_200): OperationTokenIntrospector
{
    return new OperationTokenIntrospector(
        verifier: new OperationTokenVerifier(new OperationTokenSigner),
        secretsByKeyId: ['current' => 'gateway-secret'],
        clock: $now === null ? null : static fn (): int => $now,
    );
}

function operation_token_introspector_test_token(
    string $id,
    string $node,
    string $command,
    int $issuedAt = 1_798_105_200,
    int $expiresAt = 1_798_105_320,
): string {
    return operationTokenIntrospectorTestSigner()
        ->sign(
            secret: 'gateway-secret',
            keyId: 'current',
            id: $id,
            node: $node,
            command: $command,
            commandContextHash: operation_token_introspector_test_context_hash($command),
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function operation_token_introspector_test_introspect(
    #[SensitiveParameter]
    string $compactToken,
    string $expectedNode,
    string $expectedCommand,
    ?OperationTokenIntrospector $introspector = null,
): array {
    return ($introspector ?? operationTokenIntrospector())->introspect(
        compactToken: $compactToken,
        expectedNode: $expectedNode,
        expectedCommand: $expectedCommand,
        argv: [
            $expectedCommand,
            '--operation-token='.$compactToken,
        ],
        cwd: null,
        environment: [],
        input: null,
    );
}

function operation_token_introspector_test_context_hash(string $command): string
{
    return OperationTokenCommandContext::fromTrustedDispatch(
        argv: [
            $command,
            '--operation-token='.OperationTokenCommandContext::OPERATION_TOKEN_SENTINEL,
        ],
        cwd: null,
        environment: [],
        input: null,
    )->hash();
}

function operationTokenIntrospectorTestSigner(): OperationTokenSigner
{
    return new OperationTokenSigner;
}

function operationTokenIntrospectorBase64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

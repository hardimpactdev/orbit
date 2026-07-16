<?php

declare(strict_types=1);

use Orbit\Core\Security\OperationTokenSigner;
use Orbit\Core\Security\TrustedExecutionContext;

it('round trips a trusted gateway-local execution context through environment values', function (): void {
    $compactToken = new OperationTokenSigner()
        ->sign(
            secret: trusted_execution_context_test_secret(),
            id: 'operation-1',
            node: 'gateway-main',
            command: 'internal:caddy-config',
            issuedAt: 1_798_105_200,
            expiresAt: 1_798_105_320,
        )
        ->toString();
    $context = TrustedExecutionContext::fromOperationToken(
        compactToken: $compactToken,
        lane: TrustedExecutionContext::GATEWAY_LOCAL_LANE,
    );
    $restored = TrustedExecutionContext::fromEnvironment($context->environment());

    expect($restored)
        ->not
        ->toBeNull()
        ->and($restored?->lane)
        ->toBe('gateway-local')
        ->and($restored?->authorizes($compactToken, 'internal:caddy-config'))
        ->toBeTrue();
});

it('rejects trusted execution environment values that do not match the token', function (): void {
    $compactToken = new OperationTokenSigner()
        ->sign(
            secret: trusted_execution_context_test_secret(),
            id: 'operation-1',
            node: 'gateway-main',
            command: 'internal:caddy-config',
            issuedAt: 1_798_105_200,
            expiresAt: 1_798_105_320,
        )
        ->toString();
    $environment = TrustedExecutionContext::fromOperationToken(
        compactToken: $compactToken,
        lane: TrustedExecutionContext::GATEWAY_LOCAL_LANE,
    )->environment();
    $environment[TrustedExecutionContext::OPERATION_ID_ENVIRONMENT_KEY] = 'operation-2';

    expect(TrustedExecutionContext::fromEnvironment($environment))
        ->toBeNull();
});

function trusted_execution_context_test_secret(): string
{
    return hash('sha256', __FILE__);
}

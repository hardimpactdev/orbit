<?php

declare(strict_types=1);

use App\Exceptions\OperationTokenGuardException;
use App\Services\Executor\OperationTokenGuard;
use App\Services\GatewayApiClient;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Security\OperationTokenSigner;

/**
 * @return list<string>
 */
function operation_token_guard_safe_denial_reasons(): array
{
    return [
        'invalid_token',
        'arguments_mismatch',
        'target_node_mismatch',
        'command_mismatch',
        'operation.already_dispatched',
        'operation.not_found',
    ];
}

function signed_denial_reason_token(
    string $id = 'op-denial-reason',
    string $command = 'internal:executor:verify',
): string {
    return new OperationTokenSigner()
        ->sign(
            secret: implode('-', ['gateway', 'secret']),
            id: $id,
            node: 'app-dev',
            command: $command,
            issuedAt: time() - 10,
            expiresAt: time() + 120,
        )
        ->toString();
}

function make_operation_token_guard(): OperationTokenGuard
{
    return new OperationTokenGuard(
        resolveGateway: static fn (): GatewayApiClient => app(GatewayApiClient::class),
    );
}

it('propagates recognized gateway denial reasons on the guard exception', function (string $reason): void {
    fakeGateway(fakeSuccessEnvelope([
        'allowed' => false,
        'reason' => $reason,
        'operation_id' => 'op-denial-reason',
    ]));

    $guard = make_operation_token_guard();

    try {
        $guard->verify(signed_denial_reason_token(), 'internal:executor:verify');
        $this->fail('Expected OperationTokenGuardException was not thrown.');
    } catch (OperationTokenGuardException $exception) {
        expect($exception->reason())
            ->toBe($reason)
            ->and($exception->getMessage())
            ->toBe('Operation token is invalid.');
    }
})->with(operation_token_guard_safe_denial_reasons());

it('collapses unknown gateway denial reasons to invalid_token', function (): void {
    fakeGateway(fakeSuccessEnvelope([
        'allowed' => false,
        'reason' => 'sqlstate_connection_refused_at_/var/run/postgresql',
        'operation_id' => 'op-unknown-reason',
    ]));

    $guard = make_operation_token_guard();

    try {
        $guard->verify(signed_denial_reason_token(id: 'op-unknown-reason'), 'internal:executor:verify');
        $this->fail('Expected OperationTokenGuardException was not thrown.');
    } catch (OperationTokenGuardException $exception) {
        expect($exception->reason())
            ->toBe('invalid_token')
            ->and($exception->getMessage())
            ->toBe('Operation token is invalid.')
            ->and($exception->getMessage())
            ->not->toContain('sqlstate')->and($exception->getMessage())
            ->not->toContain('/var/run/postgresql');
    }
});

it('maps malformed gateway verify responses to invalid_token without retaining response details', function (): void {
    fakeGateway(fakeSuccessEnvelope([
        'allowed' => 'yes',
        'reason' => 'arguments_mismatch',
        'debug' => 'argv hash mismatch for /home/orbit/.config/orbit',
    ]));

    $guard = make_operation_token_guard();

    try {
        $guard->verify(signed_denial_reason_token(id: 'op-malformed'), 'internal:executor:verify');
        $this->fail('Expected OperationTokenGuardException was not thrown.');
    } catch (OperationTokenGuardException $exception) {
        expect($exception->reason())
            ->toBe('invalid_token')
            ->and($exception->getMessage())
            ->toBe('Operation token is invalid.')
            ->and($exception->getMessage())
            ->not->toContain('argv')->and($exception->getMessage())
            ->not->toContain('/home/orbit')->and($exception->getMessage())
            ->not->toContain('debug');
    }
});

it('maps gateway transport failures to invalid_token without leaking network text', function (): void {
    fakeGatewayDown('No route to host via 10.10.0.1 for token verify');

    $guard = make_operation_token_guard();

    try {
        $guard->verify(signed_denial_reason_token(id: 'op-transport'), 'internal:executor:verify');
        $this->fail('Expected OperationTokenGuardException was not thrown.');
    } catch (OperationTokenGuardException $exception) {
        expect($exception->reason())
            ->toBe('invalid_token')
            ->and($exception->getMessage())
            ->toBe('Operation token is invalid.')
            ->and($exception->getMessage())
            ->not->toContain('No route to host')->and($exception->getMessage())
            ->not->toContain('10.10.0.1');
    }
});

it('does not treat a missing denial reason as a free-form payload leak surface', function (): void {
    fakeGateway(fakeSuccessEnvelope([
        'allowed' => false,
        'operation_id' => 'op-missing-reason',
    ]));

    $guard = make_operation_token_guard();

    try {
        $guard->verify(signed_denial_reason_token(id: 'op-missing-reason'), 'internal:executor:verify');
        $this->fail('Expected OperationTokenGuardException was not thrown.');
    } catch (OperationTokenGuardException $exception) {
        expect($exception->reason())->toBe('invalid_token');
    }

    Http::assertSentCount(1);
});

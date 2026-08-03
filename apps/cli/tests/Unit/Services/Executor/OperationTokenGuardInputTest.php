<?php

declare(strict_types=1);

use App\Services\Executor\OperationStdinBuffer;
use App\Services\Executor\OperationTokenGuard;
use App\Services\GatewayApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Security\OperationTokenSigner;

it('includes buffered stdin input in the host verification payload', function (): void {
    fakeGateway(fakeSuccessEnvelope([
        'allowed' => true,
        'reason' => null,
        'operation_id' => 'op-input-bound',
    ]));

    $buffer = new OperationStdinBuffer;
    $boundInput = json_encode(['container' => 'orbit-caddy'], JSON_THROW_ON_ERROR);
    $buffer->prime($boundInput);

    $token = new OperationTokenSigner()
        ->sign(
            secret: implode('-', ['gateway', 'secret']),
            id: 'op-input-bound',
            node: 'gateway',
            command: 'internal:caddy-config',
            issuedAt: time() - 10,
            expiresAt: time() + 120,
        )
        ->toString();

    $guard = new OperationTokenGuard(
        resolveGateway: static fn (): GatewayApiClient => app(GatewayApiClient::class),
        stdinBuffer: $buffer,
    );

    $guard->verify($token, 'internal:caddy-config');

    Http::assertSent(static function (Request $request) use ($token, $boundInput): bool {
        $operationToken = $request['operation_token'] ?? null;
        $input = $request['input'] ?? null;

        return (
            $request->url() === 'https://gateway.test/api/internal-executor/token/verify'
            && is_string($operationToken)
            && hash_equals($token, $operationToken)
            && $request['command'] === 'internal:caddy-config'
            && is_string($input)
            && hash_equals($boundInput, $input)
        );
    });

    expect(hash_equals($boundInput, $buffer->take()))->toBeTrue();
});

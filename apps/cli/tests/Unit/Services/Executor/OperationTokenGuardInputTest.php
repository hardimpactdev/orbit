<?php

declare(strict_types=1);

use App\Services\Executor\OperationStdinBuffer;
use App\Services\Executor\OperationTokenGuard;
use App\Services\GatewayApiClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Security\OperationTokenEnvironment;
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

it('posts only the shared allowlisted process environment for verification', function (): void {
    fakeGateway(fakeSuccessEnvelope([
        'allowed' => true,
        'reason' => null,
        'operation_id' => 'op-allowlisted-env',
    ]));

    $previous = operation_token_guard_capture_process_environment();

    putenv('APP_KEY=cli-secret');
    putenv('HOME=/home/orbit');
    putenv('ORBIT_BIN_PATH=/home/orbit/.local/bin/orbit');
    putenv('ORBIT_CONFIG_PATH=/home/orbit/.config/orbit/config.json');
    putenv('NOT_BOUND=must-not-post');

    try {
        $token = new OperationTokenSigner()
            ->sign(
                secret: implode('-', ['gateway', 'secret']),
                id: 'op-allowlisted-env',
                node: 'gateway',
                command: 'internal:caddy-config',
                issuedAt: time() - 10,
                expiresAt: time() + 120,
            )
            ->toString();

        $guard = new OperationTokenGuard(
            resolveGateway: static fn (): GatewayApiClient => app(GatewayApiClient::class),
        );

        $guard->verify($token, 'internal:caddy-config');

        Http::assertSent(
            static fn (Request $request): bool => operation_token_guard_allowlisted_environment_request_matches(
                $request,
                $token,
            ),
        );
    } finally {
        operation_token_guard_restore_process_environment($previous);
    }
});

/**
 * @return array<string, string|false>
 */
function operation_token_guard_capture_process_environment(): array
{
    $previous = [];

    foreach (['APP_KEY', 'HOME', 'ORBIT_BIN_PATH', 'ORBIT_CONFIG_PATH', 'NOT_BOUND'] as $key) {
        $previous[$key] = getenv($key);
    }

    return $previous;
}

/**
 * @param  array<string, string|false>  $previous
 */
function operation_token_guard_restore_process_environment(array $previous): void
{
    foreach ($previous as $key => $value) {
        if ($value === false) {
            putenv($key);

            continue;
        }

        putenv("{$key}={$value}");
    }
}

function operation_token_guard_allowlisted_environment_request_matches(
    Request $request,
    #[SensitiveParameter]
    string $token,
): bool {
    $operationToken = $request['operation_token'] ?? null;
    $environment = $request['environment'] ?? null;

    if (
        $request->url() !== 'https://gateway.test/api/internal-executor/token/verify'
        || ! is_string($operationToken)
        || ! hash_equals($token, $operationToken)
        || ! is_array($environment)
    ) {
        return false;
    }

    $expected = OperationTokenEnvironment::fromProcess();
    $postedAppKey = $environment['APP_KEY'] ?? null;
    $postedHome = $environment['HOME'] ?? null;

    return (
        $environment === $expected
        && ! array_key_exists('NOT_BOUND', $environment)
        && is_string($postedHome)
        && hash_equals('/home/orbit', $postedHome)
        && is_string($postedAppKey)
        && hash_equals('cli-secret', $postedAppKey)
    );
}

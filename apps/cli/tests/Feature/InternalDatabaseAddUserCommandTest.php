<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal database add-user command', function (): void {
    beforeEach(function (): void {
        configureDatabaseAddUserOperationTokenGuard();
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    it('rejects a missing operation token before reading stdin', function (): void {
        [$exitCode, $output] = runInternalDatabaseAddUserCommand(['--json' => true]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('rejects an invalid operation token before reading stdin', function (): void {
        config()->set('orbit.gateway.url', null);
        app()->forgetInstance('App\Services\GatewayApiClient');
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');

        [$exitCode, $output] = runInternalDatabaseAddUserCommand([
            '--operation-token' => 'not-a-token',
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'invalid_token',
                'Operation token is invalid.',
            ));
    });

    it('emits validation failures as strict json after token validation', function (): void {
        [$exitCode, $output] = runInternalDatabaseAddUserCommand([
            '--operation-token' => databaseAddUserSignedOperationToken(),
            '--json' => true,
        ], 'not-json');

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Database add-user payload is invalid.',
            ));
    });
});

function configureDatabaseAddUserOperationTokenGuard(): void
{
    app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
}

function databaseAddUserSignedOperationToken(
    string $id = 'database-add-user',
    string $node = 'app-dev',
    string $command = 'internal:database-add-user',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: 'gateway-secret',
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function runInternalDatabaseAddUserCommand(array $parameters = [], string $stdin = ''): array
{
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput($parameters);
    $input->setStream($stream);

    $output = new BufferedOutput;
    $exitCode = Artisan::all()['internal:database-add-user']->run($input, $output);

    return [$exitCode, trim($output->fetch())];
}

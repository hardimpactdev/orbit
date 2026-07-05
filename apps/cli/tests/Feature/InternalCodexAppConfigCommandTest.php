<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal codex app config command', function (): void {
    beforeEach(function (): void {
        configure_codex_app_config_operation_token_guard();
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    it('rejects a missing operation token before reading stdin', function (): void {
        [$exitCode, $output] = run_internal_codex_app_config_command(['--json' => true]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('rejects invalid json payloads after token validation', function (): void {
        [$exitCode, $output] = run_internal_codex_app_config_command([
            '--operation-token' => codex_app_config_signed_operation_token(),
            '--json' => true,
        ], stdin: 'not-json');

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Codex App config payload is invalid.',
            ));
    });

    it('rejects unknown actions', function (): void {
        [$exitCode, $output] = run_internal_codex_app_config_command(
            [
                '--operation-token' => codex_app_config_signed_operation_token(),
                '--json' => true,
            ],
            json_encode([
                'action' => 'delete',
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('validation_failed')
            ->and($payload['error']['message'] ?? null)
            ->toBe('Codex App config action is invalid.');
    });
});

function configure_codex_app_config_operation_token_guard(): void
{
    app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
}

function codex_app_config_signed_operation_token(
    string $id = 'codex-app-config',
    string $node = 'agent',
    string $command = 'internal:codex-app-config',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: implode('-', ['gateway', 'secret']),
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
function run_internal_codex_app_config_command(array $parameters = [], string $stdin = ''): array
{
    $stream = fopen(filename: 'php://temp', mode: 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput($parameters);
    $input->setStream($stream);

    $output = new BufferedOutput;
    $exitCode = Artisan::all()['internal:codex-app-config']->run($input, $output);

    return [$exitCode, trim($output->fetch())];
}

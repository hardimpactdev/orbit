<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal app runtime command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    it('rejects a missing operation token before reading stdin', function (): void {
        [$exitCode, $output] = run_internal_app_runtime_command('container:apply', ['--json' => true]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('rejects invalid actions after token validation', function (): void {
        [$exitCode, $output] = run_internal_app_runtime_command('delete-everything', [
            '--operation-token' => app_runtime_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'App runtime action is invalid.',
                ['field' => 'action'],
            ));
    });

    it('validates container apply specs after token validation', function (): void {
        [$exitCode, $output] = run_internal_app_runtime_command(
            'container:apply',
            [
                '--operation-token' => app_runtime_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'spec' => null,
                'runtime_config' => null,
            ], JSON_THROW_ON_ERROR),
        );

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'App runtime container spec is invalid.',
                ['field' => 'spec'],
            ));
    });

    it('rejects runtime config writes outside the managed Orbit config roots', function (): void {
        [$exitCode, $output] = run_internal_app_runtime_command(
            'runtime-config:write',
            [
                '--operation-token' => app_runtime_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'runtime_config' => [
                    'path' => '/etc/sudoers',
                    'content_base64' => base64_encode('memory_limit=512M'),
                    'directories' => [],
                    'trust_pool' => null,
                ],
            ], JSON_THROW_ON_ERROR),
        );

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'App runtime path is invalid.',
                ['field' => 'runtime_config.path'],
            ));
    });

    it('rejects arbitrary privileged runtime config directories', function (): void {
        [$exitCode, $output] = run_internal_app_runtime_command(
            'runtime-config:write',
            [
                '--operation-token' => app_runtime_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'runtime_config' => [
                    'path' => '/etc/orbit/apps/docs.ini',
                    'content_base64' => base64_encode('memory_limit=512M'),
                    'directories' => [
                        [
                            'path' => '/etc',
                            'mode' => '0755',
                            'owner' => null,
                            'group' => null,
                        ],
                    ],
                    'trust_pool' => null,
                ],
            ], JSON_THROW_ON_ERROR),
        );

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'App runtime config directory is invalid.',
                ['field' => 'runtime_config.directories.path'],
            ));
    });
});

function app_runtime_signed_operation_token(
    string $id = 'app-runtime-container',
    string $node = 'app-dev',
    string $command = 'internal:app-runtime-container',
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
function run_internal_app_runtime_command(string $action, array $parameters = [], string $stdin = ''): array
{
    $stream = fopen(filename: 'php://temp', mode: 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput([
        'action' => $action,
        ...$parameters,
    ]);
    $input->setStream($stream);

    $output = new BufferedOutput;
    $command = Artisan::all()['internal:app-runtime-container'] ?? null;

    if (! $command instanceof Command) {
        throw new RuntimeException('The internal app runtime command is not registered.');
    }

    $exitCode = $command->run($input, $output);

    return [$exitCode, trim($output->fetch())];
}

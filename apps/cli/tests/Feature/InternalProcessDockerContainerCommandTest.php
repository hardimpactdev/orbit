<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal process Docker container command', function (): void {
    beforeEach(function (): void {
        configure_process_docker_container_operation_token_guard();
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    it('rejects a missing operation token before reading stdin', function (): void {
        [$exitCode, $output] = run_internal_process_docker_container_command(['--json' => true]);

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

        [$exitCode, $output] = run_internal_process_docker_container_command([
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
        [$exitCode, $output] = run_internal_process_docker_container_command([
            '--operation-token' => process_docker_container_signed_operation_token(),
            '--json' => true,
        ], stdin: 'not-json');

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Process Docker container payload is invalid.',
            ));
    });

    it('rejects invalid Docker apply prerequisite flags before running Docker', function (): void {
        [$exitCode, $output] = run_internal_process_docker_container_command(
            [
                '--operation-token' => process_docker_container_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'action' => 'apply',
                'prepare_prerequisites' => 'yes',
                'spec' => process_docker_container_spec_payload(),
            ], JSON_THROW_ON_ERROR),
        );

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Docker container prepare_prerequisites flag is invalid.',
                ['field' => 'prepare_prerequisites'],
            ));
    });

    it('accepts Docker probe and ensure-network actions before validating their specs', function (string $action): void {
        [$exitCode, $output] = run_internal_process_docker_container_command(
            [
                '--operation-token' => process_docker_container_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'action' => $action,
                'spec' => null,
            ], JSON_THROW_ON_ERROR),
        );

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Docker process container spec is invalid.',
                ['field' => 'spec'],
            ));
    })->with(['ensure-network', 'probe']);
});

function configure_process_docker_container_operation_token_guard(): void
{
    app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
}

function process_docker_container_signed_operation_token(
    string $id = 'process-docker-container',
    string $node = 'app-dev',
    string $command = 'internal:process-docker-container',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: process_docker_container_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function process_docker_container_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

/**
 * @return array<string, mixed>
 */
function process_docker_container_spec_payload(): array
{
    return [
        'name' => 'orbit_docs_main_queue',
        'image' => 'ghcr.io/hardimpactdev/orbit-frankenphp:1-php8.5-bookworm',
        'network' => 'orbit-network',
        'restart_policy' => 'always',
        'app_slug' => 'docs',
        'workspace_slug' => null,
        'process_slug' => 'queue',
        'working_directory' => '/app',
        'command' => 'php artisan queue:work',
        'command_mode' => 'shell',
        'environment' => [],
        'mounts' => [
            [
                'source' => '/srv/docs',
                'target' => '/app',
                'read_only' => false,
            ],
        ],
        'volumes' => [],
        'ports' => [],
        'network_aliases' => ['orbit_docs_main_queue'],
        'expected_hash' => str_repeat('a', times: 64),
    ];
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function run_internal_process_docker_container_command(array $parameters = [], string $stdin = ''): array
{
    $stream = fopen(filename: 'php://temp', mode: 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput($parameters);
    $input->setStream($stream);

    $output = new BufferedOutput;
    $command = Artisan::all()['internal:process-docker-container'] ?? null;

    if (! $command instanceof Command) {
        throw new RuntimeException('The internal process Docker container command is not registered.');
    }

    $exitCode = $command->run($input, $output);

    return [$exitCode, trim($output->fetch())];
}

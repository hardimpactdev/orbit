<?php

declare(strict_types=1);

use App\Services\Executor\OperationTokenGuard;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;

describe('internal process Docker Swarm service command', function (): void {
    beforeEach(function (): void {
        configure_process_docker_swarm_service_operation_token_guard();
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    it('rejects a missing operation token before running Docker', function (): void {
        $exitCode = Artisan::call('internal:process-docker-swarm-service', [
            'action' => 'start',
            'service' => 'orbit-redis-7',
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure('missing_token', 'Operation token is required.'));
    });

    it('rejects invalid service names after token validation', function (): void {
        $exitCode = Artisan::call('internal:process-docker-swarm-service', [
            'action' => 'start',
            'service' => '../bad',
            '--operation-token' => process_docker_swarm_service_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure('validation_failed', 'Docker Swarm service name is invalid.', [
                'field' => 'service',
            ]));
    });

    it('rejects invalid lifecycle actions after token validation', function (): void {
        $exitCode = Artisan::call('internal:process-docker-swarm-service', [
            'action' => 'scale',
            'service' => 'orbit-redis-7',
            '--operation-token' => process_docker_swarm_service_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure('validation_failed', 'Docker Swarm service action is invalid.', [
                'field' => 'action',
            ]));
    });

    it('requires a service spec for apply actions', function (): void {
        $exitCode = Artisan::call('internal:process-docker-swarm-service', [
            'action' => 'apply',
            'service' => 'orbit-redis-7',
            '--operation-token' => process_docker_swarm_service_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure('validation_failed', 'Docker Swarm service spec is invalid.', [
                'field' => 'image',
            ]));
    });

    it('initializes Docker Swarm when the local node is inactive', function (): void {
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
            $commands[] = $command;

            return str_contains($command, 'Swarm.LocalNodeState')
                ? Process::result(output: "inactive\n")
                : Process::result();
        });

        $exitCode = Artisan::call('internal:process-docker-swarm-service', [
            'action' => 'ensure',
            'service' => 'orbit-runtime',
            '--operation-token' => process_docker_swarm_service_signed_operation_token(),
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data']['action'])
            ->toBe('ensure')
            ->and($payload['success']['data']['changed'])
            ->toBeTrue()
            ->and($commands)
            ->toHaveCount(2)
            ->and($commands[1])
            ->toBe('docker swarm init');
    });

    it('reuses an active Docker Swarm manager', function (): void {
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
            $commands[] = $command;

            return Process::result(output: "active\n");
        });

        $exitCode = Artisan::call('internal:process-docker-swarm-service', [
            'action' => 'ensure',
            'service' => 'orbit-runtime',
            '--operation-token' => process_docker_swarm_service_signed_operation_token(),
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data']['action'])
            ->toBe('ensure')
            ->and($payload['success']['data']['changed'])
            ->toBeFalse()
            ->and($commands)
            ->toHaveCount(1);
    });
});

function configure_process_docker_swarm_service_operation_token_guard(): void
{
    app()->forgetInstance(OperationTokenGuard::class);
}

function process_docker_swarm_service_signed_operation_token(
    string $id = 'process-docker-swarm-service',
    string $node = 'database',
    string $command = 'internal:process-docker-swarm-service',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: process_docker_swarm_service_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function process_docker_swarm_service_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

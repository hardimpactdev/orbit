<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
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
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
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
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Docker Swarm service name is invalid.',
                ['field' => 'service'],
            ));
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
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Docker Swarm service action is invalid.',
                ['field' => 'action'],
            ));
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
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Docker Swarm service spec is invalid.',
                ['field' => 'image'],
            ));
    });
});

function configure_process_docker_swarm_service_operation_token_guard(): void
{
    app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
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

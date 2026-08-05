<?php

declare(strict_types=1);

use App\Services\Executor\OperationTokenGuard;
use App\Services\Processes\LocalDockerSwarmServiceAction;
use App\Services\Processes\LocalDockerSwarmServiceFailure;
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
            'service' => 'orbit-valkey-8',
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
            'service' => 'orbit-valkey-8',
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
            'service' => 'orbit-valkey-8',
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

        $result = app(LocalDockerSwarmServiceAction::class)->run('ensure', 'orbit-runtime', [
            'advertise_address' => '10.6.0.8',
        ]);

        expect($result['action'])
            ->toBe('ensure')
            ->and($result['changed'])
            ->toBeTrue()
            ->and($commands)
            ->toHaveCount(2)
            ->and($commands[1])
            ->toBe('docker swarm init --advertise-addr 10.6.0.8');
    });

    it('reuses an active Docker Swarm manager', function (): void {
        $commands = [];
        Process::fake(function (PendingProcess $process) use (&$commands) {
            $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;
            $commands[] = $command;

            return str_contains($command, 'ControlAvailable')
                ? Process::result(output: "true\n")
                : Process::result(output: "active\n");
        });

        $result = app(LocalDockerSwarmServiceAction::class)->run('ensure', 'orbit-runtime', [
            'advertise_address' => '10.6.0.8',
        ]);

        expect($result['action'])
            ->toBe('ensure')
            ->and($result['changed'])
            ->toBeFalse()
            ->and($commands)
            ->toHaveCount(2);
    });

    it('rejects an active Swarm worker that cannot manage services', function (): void {
        Process::fake(function (PendingProcess $process) {
            $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($command, 'ControlAvailable')
                ? Process::result(output: "false\n")
                : Process::result(output: "active\n");
        });

        expect(fn (): array => app(LocalDockerSwarmServiceAction::class)->run('ensure', 'orbit-runtime', [
            'advertise_address' => '10.6.0.8',
        ]))
            ->toThrow(LocalDockerSwarmServiceFailure::class, 'not a Swarm manager');
    });

    it('requires a valid IP address for Swarm advertisement', function (string $advertiseAddress): void {
        Process::fake();

        expect(fn (): array => app(LocalDockerSwarmServiceAction::class)->run('ensure', 'orbit-runtime', [
            'advertise_address' => $advertiseAddress,
        ]))
            ->toThrow(LocalDockerSwarmServiceFailure::class, 'advertise address is invalid');
    })->with(['', 'wg0', 'not-an-ip']);

    it('treats removal of an absent Swarm service as converged', function (): void {
        Process::fake(fn (PendingProcess $process) => Process::result(
            errorOutput: 'Error response from daemon: no such service: orbit-plausible',
            exitCode: 1,
        ));

        $result = app(LocalDockerSwarmServiceAction::class)->run('remove', 'orbit-plausible');

        expect($result)
            ->toBe([
                'action' => 'remove',
                'service' => 'orbit-plausible',
                'changed' => false,
            ]);
    });

    it('treats a swarm service as active only when observed running tasks meet desired tasks', function (): void {
        Process::fake(fn (PendingProcess $process) => Process::result(output: "1 1\n"));

        $result = app(LocalDockerSwarmServiceAction::class)->run('is-active', 'orbit-valkey-8');

        expect($result)
            ->toBe([
                'action' => 'is-active',
                'service' => 'orbit-valkey-8',
                'changed' => false,
            ]);
    });

    it('rejects swarm is-active when desired replicas are not yet running', function (): void {
        Process::fake(fn (PendingProcess $process) => Process::result(output: "0 1\n"));

        expect(fn (): array => app(LocalDockerSwarmServiceAction::class)->run('is-active', 'orbit-valkey-8'))
            ->toThrow(LocalDockerSwarmServiceFailure::class);
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

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal deploy run step command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope(['allowed' => true]));
    });

    it('rejects a missing operation token before reading stdin', function (): void {
        [$exitCode, $output] = run_internal_deploy_run_step_command(['--json' => true]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure('missing_token', 'Operation token is required.'));
    });

    it('runs the approved deploy payload with its cwd environment and timeout', function (): void {
        $cwd = realpath('/tmp') ?: '/tmp';

        [$exitCode, $output] = run_internal_deploy_run_step_command(
            [
                '--operation-token' => deploy_run_step_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'binary' => '/bin/sh',
                'arguments' => ['-lc', 'printf "$PWD|$ORBIT_DEPLOY_APP_NAME"'],
                'cwd' => $cwd,
                'environment' => ['ORBIT_DEPLOY_APP_NAME' => 'docs'],
                'timeout' => 30,
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data']['exit_code'] ?? null)
            ->toBe(0)
            ->and($payload['success']['data']['stdout'] ?? null)
            ->toBe("{$cwd}|docs")
            ->and($payload['success']['data']['duration_ms'] ?? null)
            ->toBeInt();
    });

    it('rejects relative working directories', function (): void {
        [$exitCode, $output] = run_internal_deploy_run_step_command(
            [
                '--operation-token' => deploy_run_step_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'binary' => '/bin/sh',
                'arguments' => ['-lc', 'printf unsafe'],
                'cwd' => 'relative/path',
                'environment' => [],
                'timeout' => 30,
            ], JSON_THROW_ON_ERROR),
        );

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR)['error']['code'] ?? null)
            ->toBe('validation_failed');
    });

    it('reports the node-side reason when the working directory is missing', function (): void {
        [$exitCode, $output] = run_internal_deploy_run_step_command(
            [
                '--operation-token' => deploy_run_step_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'binary' => '/bin/sh',
                'arguments' => ['-lc', 'printf ok'],
                'cwd' => '/tmp/orbit-deploy-run-step-missing-cwd',
                'environment' => [],
                'timeout' => 30,
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('deploy_run_step_failed')
            ->and($payload['error']['message'] ?? null)
            ->toContain('/tmp/orbit-deploy-run-step-missing-cwd')
            ->and($payload['error']['message'] ?? null)
            ->toContain('does not exist');
    });

    it('rejects a non-absolute executable', function (): void {
        [$exitCode, $output] = run_internal_deploy_run_step_command(
            [
                '--operation-token' => deploy_run_step_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'binary' => 'sh',
                'arguments' => ['-lc', 'printf unsafe'],
                'cwd' => '/tmp',
                'environment' => [],
                'timeout' => 30,
            ], JSON_THROW_ON_ERROR),
        );

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR)['error']['code'] ?? null)
            ->toBe('validation_failed');
    });
});

function deploy_run_step_signed_operation_token(): string
{
    return new OperationTokenSigner()
        ->sign(
            secret: hash('sha256', __FILE__),
            id: 'deploy-run-step',
            node: 'app-prod-1',
            command: 'internal:deploy:run-step',
            issuedAt: time() - 10,
            expiresAt: time() + 120,
        )
        ->toString();
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function run_internal_deploy_run_step_command(array $parameters = [], string $stdin = ''): array
{
    $stream = fopen(filename: 'php://temp', mode: 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput($parameters);
    $input->setStream($stream);

    $output = new BufferedOutput;
    $command = Artisan::all()['internal:deploy:run-step'] ?? null;

    if (! $command instanceof Command) {
        throw new RuntimeException('The internal deploy run step command is not registered.');
    }

    $exitCode = $command->run($input, $output);

    return [$exitCode, trim($output->fetch())];
}

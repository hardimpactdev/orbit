<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal schedule run command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    it('rejects a missing operation token before reading stdin', function (): void {
        [$exitCode, $output] = run_internal_schedule_run_command(['--json' => true]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('returns command output and exit code without failing the internal command', function (): void {
        $cwd = sys_get_temp_dir();
        $realCwd = realpath($cwd);
        $resolvedCwd = $realCwd !== false ? $realCwd : $cwd;

        [$exitCode, $output] = run_internal_schedule_run_command(
            [
                '--operation-token' => schedule_run_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'execution_type' => 'command',
                'execution_value' => 'printf "$PWD"',
                'cwd' => $cwd,
                'timeout' => 60,
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data']['exit_code'] ?? null)
            ->toBe(0)
            ->and($payload['success']['data']['stdout'] ?? null)
            ->toBe($resolvedCwd);
    });

    it('captures non-zero schedule command exits as successful internal responses', function (): void {
        [$exitCode, $output] = run_internal_schedule_run_command(
            [
                '--operation-token' => schedule_run_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'execution_type' => 'command',
                'execution_value' => 'printf failed >&2; exit 7',
                'cwd' => null,
                'timeout' => 60,
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data']['exit_code'] ?? null)
            ->toBe(7)
            ->and($payload['success']['data']['stderr'] ?? null)
            ->toBe('failed');
    });

    it('accepts the maximum timeout supported by schedule configuration', function (): void {
        [$exitCode, $output] = run_internal_schedule_run_command(
            [
                '--operation-token' => schedule_run_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'execution_type' => 'command',
                'execution_value' => 'printf accepted',
                'cwd' => null,
                'timeout' => 86_400,
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data']['stdout'] ?? null)
            ->toBe('accepted');
    });

    it('runs script execution values with the existing schedule quoting contract', function (): void {
        $scriptPath = tempnam(directory: sys_get_temp_dir(), prefix: 'orbit-schedule-');

        if ($scriptPath === false) {
            throw new RuntimeException('Unable to allocate temporary script path.');
        }

        try {
            file_put_contents(filename: $scriptPath, data: "#!/bin/sh\nprintf script-ran\n");
            chmod(filename: $scriptPath, permissions: 0o755);

            [$exitCode, $output] = run_internal_schedule_run_command(
                [
                    '--operation-token' => schedule_run_signed_operation_token(),
                    '--json' => true,
                ],
                stdin: json_encode([
                    'execution_type' => 'script',
                    'execution_value' => $scriptPath,
                    'cwd' => null,
                    'timeout' => 60,
                ], JSON_THROW_ON_ERROR),
            );

            $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(0)
                ->and($payload['success']['data']['exit_code'] ?? null)
                ->toBe(0)
                ->and($payload['success']['data']['stdout'] ?? null)
                ->toBe('script-ran');
        } finally {
            if (is_file($scriptPath)) {
                unlink($scriptPath);
            }
        }
    });
});

function schedule_run_signed_operation_token(
    string $id = 'schedule-run',
    string $node = 'app-dev',
    string $command = 'internal:schedule:run',
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
function run_internal_schedule_run_command(array $parameters = [], string $stdin = ''): array
{
    $stream = fopen(filename: 'php://temp', mode: 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput($parameters);
    $input->setStream($stream);

    $output = new BufferedOutput;
    $command = Artisan::all()['internal:schedule:run'] ?? null;

    if (! $command instanceof Command) {
        throw new RuntimeException('The internal schedule run command is not registered.');
    }

    $exitCode = $command->run($input, $output);

    return [$exitCode, trim($output->fetch())];
}

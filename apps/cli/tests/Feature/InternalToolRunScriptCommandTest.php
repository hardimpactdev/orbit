<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal tool run script command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    it('rejects a missing operation token before reading stdin', function (): void {
        [$exitCode, $output] = run_internal_tool_run_script_command(['--json' => true]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('rejects payloads with unsupported actions', function (): void {
        [$exitCode, $output] = run_internal_tool_run_script_command(
            [
                '--operation-token' => tool_run_script_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'tool' => 'node-exporter',
                'action' => 'arbitrary-shell',
                'script' => 'printf ok',
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('validation_failed');
    });

    it('rejects payloads missing required tool metadata', function (): void {
        [$exitCode, $output] = run_internal_tool_run_script_command(
            [
                '--operation-token' => tool_run_script_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'action' => 'install',
                'script' => 'printf ok',
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('validation_failed');
    });

    it('returns command output and exit code without failing the internal command', function (): void {
        [$exitCode, $output] = run_internal_tool_run_script_command(
            [
                '--operation-token' => tool_run_script_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'tool' => 'node-exporter',
                'action' => 'install',
                'script' => 'printf installed',
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data']['exit_code'] ?? null)
            ->toBe(0)
            ->and($payload['success']['data']['stdout'] ?? null)
            ->toBe('installed')
            ->and($payload['success']['data']['duration_ms'] ?? null)
            ->toBeInt();
    });

    it('runs catalog scripts explicitly with Bash', function (): void {
        [$exitCode, $output] = run_internal_tool_run_script_command(
            [
                '--operation-token' => tool_run_script_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'tool' => 'node-exporter',
                'action' => 'reconfigure',
                'script' => 'set -euo pipefail; test "$0" = bash; printf bash',
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data']['exit_code'] ?? null)
            ->toBe(0)
            ->and($payload['success']['data']['stdout'] ?? null)
            ->toBe('bash');
    });

    it('captures non-zero script exits as successful internal responses', function (): void {
        [$exitCode, $output] = run_internal_tool_run_script_command(
            [
                '--operation-token' => tool_run_script_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'tool' => 'node-exporter',
                'action' => 'update',
                'script' => 'printf failed >&2; exit 7',
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

    it('runs scripts from the fixed cwd regardless of payload hints', function (): void {
        $resolvedCwd = realpath('/tmp') ?: '/tmp';

        [$exitCode, $output] = run_internal_tool_run_script_command(
            [
                '--operation-token' => tool_run_script_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'tool' => 'node-exporter',
                'action' => 'install',
                'script' => 'printf "$PWD|${SHOULD_NOT-unset}"',
                'cwd' => '/should-not-control-cwd',
                'environment' => ['SHOULD_NOT' => 'control'],
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data']['stdout'] ?? null)
            ->toBe("{$resolvedCwd}|unset");
    });

    it('clamps oversized timeout values to the maximum allowed window', function (): void {
        $scriptPath = tempnam(directory: sys_get_temp_dir(), prefix: 'orbit-tool-timeout-');

        if ($scriptPath === false) {
            throw new RuntimeException('Unable to allocate temporary script path.');
        }

        try {
            file_put_contents(
                filename: $scriptPath,
                data: "#!/bin/sh\nprintf timed-out >&2; exit 124",
            );
            chmod(filename: $scriptPath, permissions: 0o755);

            [$exitCode, $output] = run_internal_tool_run_script_command(
                [
                    '--operation-token' => tool_run_script_signed_operation_token(),
                    '--json' => true,
                ],
                stdin: json_encode([
                    'tool' => 'node-exporter',
                    'action' => 'install',
                    'script' => "sh {$scriptPath}",
                    'timeout' => 999_999,
                ], JSON_THROW_ON_ERROR),
            );

            $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)
                ->toBe(0)
                ->and($payload['success']['data']['exit_code'] ?? null)
                ->toBe(124)
                ->and($payload['success']['data']['stderr'] ?? null)
                ->toBe('timed-out');
        } finally {
            if (is_file($scriptPath)) {
                unlink($scriptPath);
            }
        }
    });
});

// Kept outside the main describe so that closure stays under cyclomatic threshold.
describe('internal tool run script command probe-php-cli', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    it('accepts probe-php-cli and returns multi-minor probe stdout', function (): void {
        [$exitCode, $output] = run_internal_tool_run_script_command(
            [
                '--operation-token' => tool_run_script_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'tool' => 'php-cli',
                'action' => 'probe-php-cli',
                'script' => "printf '%s\\n' '8.5|8.5.8|1|8.5.8|1|1|1|1' '8.4|8.4.21|1|8.4.21|1|1|1|1' '8.3|8.3.31|1|8.3.31|1|1|1|1'",
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data']['exit_code'] ?? null)
            ->toBe(0)
            ->and($payload['success']['data']['stdout'] ?? null)
            ->toContain('8.5|8.5.8|1|8.5.8|1|1|1|1')
            ->toContain('8.4|8.4.21|1|8.4.21|1|1|1|1')
            ->toContain('8.3|8.3.31|1|8.3.31|1|1|1|1');
    });
});

function tool_run_script_signed_operation_token(
    string $id = 'tool-run-script',
    string $node = 'app-dev',
    string $command = 'internal:tool:run-script',
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
function run_internal_tool_run_script_command(array $parameters = [], string $stdin = ''): array
{
    $stream = fopen(filename: 'php://temp', mode: 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput($parameters);
    $input->setStream($stream);

    $output = new BufferedOutput;
    $command = Artisan::all()['internal:tool:run-script'] ?? null;

    if (! $command instanceof Command) {
        throw new RuntimeException('The internal tool run script command is not registered.');
    }

    $exitCode = $command->run($input, $output);

    return [$exitCode, trim($output->fetch())];
}

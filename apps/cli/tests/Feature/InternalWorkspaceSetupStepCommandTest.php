<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal workspace setup step command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    it('rejects a missing operation token before reading stdin', function (): void {
        [$exitCode, $output] = run_internal_workspace_setup_step_command(['--json' => true]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure('missing_token', 'Operation token is required.'));
    });

    it('returns command output and exit code without failing the internal command', function (): void {
        $cwd = sys_get_temp_dir();
        $realCwd = realpath($cwd);
        $resolvedCwd = $realCwd !== false ? $realCwd : $cwd;

        [$exitCode, $output] = run_internal_workspace_setup_step_command(
            [
                '--operation-token' => workspace_setup_step_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'command' => 'printf "$ORBIT_APP:$PWD"',
                'cwd' => $cwd,
                'timeout' => 60,
                'environment' => [
                    'ORBIT_APP' => 'happie',
                ],
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data']['exit_code'] ?? null)
            ->toBe(0)
            ->and($payload['success']['data']['stdout'] ?? null)
            ->toBe("happie:{$resolvedCwd}");
    });

    it('rejects forbidden setup environment keys', function (string $key): void {
        [$exitCode, $output] = run_internal_workspace_setup_step_command(
            [
                '--operation-token' => workspace_setup_step_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'command' => 'echo ok',
                'cwd' => '/tmp',
                'timeout' => 60,
                'environment' => [
                    $key => 'secret',
                ],
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('validation_failed');
    })->with([
        'app key' => ['APP_KEY'],
        'agent push authorized command' => ['ORBIT_AGENT_PUSH_AUTHORIZED_COMMAND'],
        'trusted execution command' => ['ORBIT_TRUSTED_EXECUTION_COMMAND'],
    ]);

    it('captures non-zero setup command exits as successful internal responses', function (): void {
        [$exitCode, $output] = run_internal_workspace_setup_step_command(
            [
                '--operation-token' => workspace_setup_step_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'command' => 'printf failed >&2; exit 7',
                'cwd' => null,
                'timeout' => 60,
                'environment' => [],
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
});

function workspace_setup_step_signed_operation_token(
    string $id = 'workspace-setup-step',
    string $node = 'app-dev',
    string $command = 'internal:workspace-setup-step',
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
function run_internal_workspace_setup_step_command(array $parameters = [], string $stdin = ''): array
{
    $stream = fopen(filename: 'php://temp', mode: 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput($parameters);
    $input->setStream($stream);

    $output = new BufferedOutput;
    $command = Artisan::all()['internal:workspace-setup-step'] ?? null;

    if (! $command instanceof Command) {
        throw new RuntimeException('The internal workspace setup step command is not registered.');
    }

    $exitCode = $command->run($input, $output);

    return [$exitCode, trim($output->fetch())];
}

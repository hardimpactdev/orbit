<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal app setup step command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    it('rejects a missing operation token before reading stdin', function (): void {
        [$exitCode, $output] = run_internal_app_setup_step_command(['--json' => true]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure('missing_token', 'Operation token is required.'));
    });

    it('rejects an invalid operation token before reading stdin', function (): void {
        config()->set('orbit.gateway.url', null);
        app()->forgetInstance('App\Services\GatewayApiClient');
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');

        [$exitCode, $output] = run_internal_app_setup_step_command([
            '--operation-token' => 'not-a-token',
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure('invalid_token', 'Operation token is invalid.'));
    });

    it('rejects payload validation failures without executing setup commands', function (string $stdin): void {
        [$exitCode, $output] = run_internal_app_setup_step_command([
            '--operation-token' => app_setup_step_signed_operation_token(),
            '--json' => true,
        ], stdin: $stdin);

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('validation_failed');
    })->with([
        'missing stdin' => [''],
        'invalid json' => ['{'],
        'missing command' => [json_encode([
            'cwd' => '/tmp',
            'timeout' => 60,
            'environment' => [],
        ], JSON_THROW_ON_ERROR)],
        'relative cwd' => [json_encode([
            'command' => 'echo ok',
            'cwd' => 'relative',
            'timeout' => 60,
            'environment' => [],
        ], JSON_THROW_ON_ERROR)],
        'invalid timeout' => [json_encode([
            'command' => 'echo ok',
            'cwd' => '/tmp',
            'timeout' => 0,
            'environment' => [],
        ], JSON_THROW_ON_ERROR)],
        'forbidden app key env' => [json_encode([
            'command' => 'echo ok',
            'cwd' => '/tmp',
            'timeout' => 60,
            'environment' => ['APP_KEY' => 'secret'],
        ], JSON_THROW_ON_ERROR)],
        'forbidden agent push env' => [json_encode([
            'command' => 'echo ok',
            'cwd' => '/tmp',
            'timeout' => 60,
            'environment' => ['ORBIT_AGENT_PUSH_AUTHORIZED_COMMAND' => 'x'],
        ], JSON_THROW_ON_ERROR)],
        'forbidden trusted execution env' => [json_encode([
            'command' => 'echo ok',
            'cwd' => '/tmp',
            'timeout' => 60,
            'environment' => ['ORBIT_TRUSTED_EXECUTION_COMMAND' => 'x'],
        ], JSON_THROW_ON_ERROR)],
    ]);

    it('returns command output and exit code without failing the internal command', function (): void {
        $cwd = sys_get_temp_dir();
        $realCwd = realpath($cwd);
        $resolvedCwd = $realCwd !== false ? $realCwd : $cwd;

        [$exitCode, $output] = run_internal_app_setup_step_command(
            [
                '--operation-token' => app_setup_step_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'command' => 'printf "$ORBIT_APP:$PWD"',
                'cwd' => $cwd,
                'timeout' => 60,
                'environment' => [
                    'ORBIT_APP' => 'docs',
                ],
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data']['exit_code'] ?? null)
            ->toBe(0)
            ->and($payload['success']['data']['stdout'] ?? null)
            ->toBe("docs:{$resolvedCwd}");
    });

    it('captures non-zero setup command exits as successful internal responses', function (): void {
        [$exitCode, $output] = run_internal_app_setup_step_command(
            [
                '--operation-token' => app_setup_step_signed_operation_token(),
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

    it('times out long-running setup commands', function (): void {
        [$exitCode, $output] = run_internal_app_setup_step_command(
            [
                '--operation-token' => app_setup_step_signed_operation_token(),
                '--json' => true,
            ],
            stdin: json_encode([
                'command' => 'sleep 5',
                'cwd' => null,
                'timeout' => 1,
                'environment' => [],
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->toBe('app_setup_step_failed');
    });
});

function app_setup_step_signed_operation_token(
    string $id = 'app-setup-step',
    string $node = 'app-dev',
    string $command = 'internal:app-setup-step',
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
function run_internal_app_setup_step_command(array $parameters = [], string $stdin = ''): array
{
    $stream = fopen(filename: 'php://temp', mode: 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput($parameters);
    $input->setStream($stream);

    $output = new BufferedOutput;
    $command = Artisan::all()['internal:app-setup-step'] ?? null;

    if (! $command instanceof Command) {
        throw new RuntimeException('The internal app setup step command is not registered.');
    }

    $exitCode = $command->run($input, $output);

    return [$exitCode, trim($output->fetch())];
}

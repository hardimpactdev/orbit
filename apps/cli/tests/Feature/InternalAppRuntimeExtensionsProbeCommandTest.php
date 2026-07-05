<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal app runtime extensions probe command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    afterEach(function (): void {
        if (isset($this->appRuntimeExtensionsOriginalPath)) {
            putenv("PATH={$this->appRuntimeExtensionsOriginalPath}");
            unset($this->appRuntimeExtensionsOriginalPath);
        }
    });

    it('rejects a missing operation token before probing Docker', function (): void {
        [$exitCode, $output] = run_internal_app_runtime_extensions_probe_command([
            'container' => 'orbit-app-docs',
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('rejects invalid container names after token validation', function (): void {
        [$exitCode, $output] = run_internal_app_runtime_extensions_probe_command([
            'container' => '../docs',
            '--operation-token' => app_runtime_extensions_probe_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'App runtime container name is invalid.',
                ['field' => 'container'],
            ));
    });

    it('emits the Docker exec exit code and output', function (): void {
        fake_app_runtime_extensions_docker_binary($this, "redis\npdo\n", '');

        [$exitCode, $output] = run_internal_app_runtime_extensions_probe_command([
            'container' => 'orbit-app-docs',
            '--operation-token' => app_runtime_extensions_probe_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::success([
                'container' => 'orbit-app-docs',
                'exit_code' => 0,
                'stdout' => "redis\npdo\n",
                'stderr' => '',
            ]));
    });
});

function app_runtime_extensions_probe_signed_operation_token(
    string $id = 'app-runtime-extensions-probe',
    string $node = 'app-dev',
    string $command = 'internal:app-runtime-extensions:probe',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: 'gateway-secret',
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
function run_internal_app_runtime_extensions_probe_command(array $parameters): array
{
    $output = new BufferedOutput;
    $exitCode = Artisan::all()['internal:app-runtime-extensions:probe']->run(new ArrayInput($parameters), $output);

    return [$exitCode, trim($output->fetch())];
}

function fake_app_runtime_extensions_docker_binary(object $test, string $stdout, string $stderr): void
{
    $directory = sys_get_temp_dir().'/orbit-app-runtime-extensions-'.bin2hex(random_bytes(8));
    mkdir("{$directory}/bin", recursive: true);
    file_put_contents("{$directory}/stdout", $stdout);
    file_put_contents("{$directory}/stderr", $stderr);

    $script = <<<SH
        #!/bin/sh
        if [ "$1" != "exec" ] || [ "$3" != "php" ] || [ "$4" != "-m" ]; then
          exit 64
        fi
        cat '{$directory}/stdout'
        cat '{$directory}/stderr' >&2
        SH;

    file_put_contents("{$directory}/bin/docker", $script);
    chmod("{$directory}/bin/docker", 0755);

    $test->appRuntimeExtensionsOriginalPath = getenv('PATH') ?: '';
    putenv("PATH={$directory}/bin:{$test->appRuntimeExtensionsOriginalPath}");
}

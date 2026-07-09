<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;

describe('internal agent runtime probe command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        $originalPath = getenv('PATH');
        $_ENV['ORBIT_AGENT_RUNTIME_ORIGINAL_PATH'] = $originalPath === false ? '' : $originalPath;
    });

    afterEach(function (): void {
        putenv('PATH='.($_ENV['ORBIT_AGENT_RUNTIME_ORIGINAL_PATH'] ?? ''));

        $fakeBinPaths = glob(sys_get_temp_dir().'/orbit-agent-runtime-bin-*');

        if ($fakeBinPaths === false) {
            return;
        }

        foreach ($fakeBinPaths as $dir) {
            delete_agent_runtime_fake_bin($dir);
        }
    });

    it('rejects a missing operation token before probing local state', function (): void {
        Artisan::call('internal:agent-runtime:probe', [
            '--json' => true,
        ]);

        expect(Artisan::output())
            ->json()
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('probes the agent user and Orbit CLI through fixed argv', function (): void {
        $bin = install_agent_runtime_fake_bin(idExitCode: 0, sudoExitCode: 126);

        $exitCode = Artisan::call('internal:agent-runtime:probe', [
            '--operation-token' => agent_runtime_signed_operation_token(),
            '--json' => true,
        ]);
        $data = agent_runtime_success_data();

        expect($exitCode)
            ->toBe(0)
            ->and($data)
            ->toMatchArray([
                'runtime_user' => true,
                'orbit_cli' => false,
                'runtime_user_exit_code' => 0,
                'orbit_cli_exit_code' => 126,
            ])
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('id -u agent')
            ->toContain(
                'sudo -n -u agent -H /usr/bin/env PATH=/home/agent/.local/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin ORBIT_CONFIG_PATH=/home/orbit/.config/orbit/config.json ORBIT_INSTALL_METADATA_PATH=/home/orbit/.config/orbit/install.json /home/agent/.local/bin/orbit --version --local',
            );
    });
});

function agent_runtime_signed_operation_token(
    string $id = 'agent-runtime-probe',
    string $node = 'agent',
    string $command = 'internal:agent-runtime:probe',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: agent_runtime_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function agent_runtime_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

/**
 * @return array<string, mixed>
 */
function agent_runtime_success_data(): array
{
    /** @var mixed $payload */
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($payload)) {
        return [];
    }

    /** @var mixed $success */
    $success = $payload['success'] ?? null;

    if (! is_array($success)) {
        return [];
    }

    /** @var mixed $data */
    $data = $success['data'] ?? null;

    if (! is_array($data)) {
        return [];
    }

    foreach (array_keys($data) as $key) {
        if (! is_string($key)) {
            return [];
        }
    }

    /** @var array<string, mixed> $data */
    return $data;
}

function install_agent_runtime_fake_bin(int $idExitCode, int $sudoExitCode): string
{
    $dir = sys_get_temp_dir().'/orbit-agent-runtime-bin-'.bin2hex(random_bytes(8));
    mkdir($dir);

    install_agent_runtime_fake_binary($dir, binary: 'id', exitCode: $idExitCode);
    install_agent_runtime_fake_binary($dir, binary: 'sudo', exitCode: $sudoExitCode);

    $path = getenv('PATH');
    putenv("PATH={$dir}:".($path === false ? '' : $path));

    return $dir;
}

function install_agent_runtime_fake_binary(string $dir, string $binary, int $exitCode): void
{
    file_put_contents("{$dir}/{$binary}", <<<PHP
        #!/usr/bin/env php
        <?php
        file_put_contents(__DIR__.'/calls.log', basename(\$argv[0]).' '.implode(' ', array_slice(\$argv, 1)).PHP_EOL, FILE_APPEND);
        exit({$exitCode});
        PHP);
    chmod(filename: "{$dir}/{$binary}", permissions: 0o755);
}

function delete_agent_runtime_fake_bin(string $path): void
{
    delete_agent_runtime_file("{$path}/id");
    delete_agent_runtime_file("{$path}/sudo");
    delete_agent_runtime_file("{$path}/calls.log");

    if (is_dir($path)) {
        rmdir($path);
    }
}

function delete_agent_runtime_file(string $path): void
{
    if (! is_file($path)) {
        return;
    }

    unlink($path);
}

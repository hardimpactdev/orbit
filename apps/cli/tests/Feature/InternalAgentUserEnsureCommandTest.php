<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;

describe('internal agent user ensure command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        $originalPath = getenv('PATH');
        $_ENV['ORBIT_AGENT_USER_ORIGINAL_PATH'] = $originalPath === false ? '' : $originalPath;
    });

    afterEach(function (): void {
        putenv('PATH='.($_ENV['ORBIT_AGENT_USER_ORIGINAL_PATH'] ?? ''));

        $fakeBinPaths = glob(sys_get_temp_dir().'/orbit-agent-user-bin-*');

        if ($fakeBinPaths === false) {
            return;
        }

        foreach ($fakeBinPaths as $dir) {
            delete_agent_user_fake_bin($dir);
        }
    });

    it('rejects a missing operation token before changing local state', function (): void {
        Artisan::call('internal:agent-user:ensure', [
            '--json' => true,
        ]);

        expect(Artisan::output())
            ->json()
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('locks an existing agent user through fixed argv', function (): void {
        $bin = install_agent_user_fake_bin(idExitCode: 0, sudoExitCode: 0);

        $exitCode = Artisan::call('internal:agent-user:ensure', [
            '--operation-token' => agent_user_signed_operation_token(),
            '--json' => true,
        ]);
        $data = agent_user_success_data();

        expect($exitCode)
            ->toBe(0)
            ->and($data)
            ->toMatchArray([
                'user' => 'agent',
                'created' => false,
                'locked' => true,
                'lock_exit_code' => 0,
            ])
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('id -u agent')
            ->toContain('sudo -n passwd -l agent')
            ->toContain('sudo -n install -d -m 0755 /home/agent/.local/bin')
            ->toContain('sudo -n install -m 0755')
            ->toContain('/home/agent/.local/bin/orbit')
            ->not->toContain('useradd');
    });

    it('creates and locks a missing agent user through fixed argv', function (): void {
        $bin = install_agent_user_fake_bin(idExitCode: 1, sudoExitCode: 0);

        $exitCode = Artisan::call('internal:agent-user:ensure', [
            '--operation-token' => agent_user_signed_operation_token(),
            '--json' => true,
        ]);
        $data = agent_user_success_data();

        expect($exitCode)
            ->toBe(0)
            ->and($data)
            ->toMatchArray([
                'user' => 'agent',
                'created' => true,
                'locked' => true,
            ])
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('id -u agent')
            ->toContain('sudo -n useradd --create-home --shell /bin/bash agent')
            ->toContain('sudo -n passwd -l agent')
            ->toContain('sudo -n install -d -m 0755 /home/agent/.local/bin')
            ->toContain('/home/agent/.local/bin/orbit');
    });
});

function agent_user_signed_operation_token(
    string $id = 'agent-user-ensure',
    string $node = 'agent',
    string $command = 'internal:agent-user:ensure',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: agent_user_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function agent_user_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

/**
 * @return array<string, mixed>
 */
function agent_user_success_data(): array
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

function install_agent_user_fake_bin(int $idExitCode, int $sudoExitCode): string
{
    $dir = sys_get_temp_dir().'/orbit-agent-user-bin-'.bin2hex(random_bytes(8));
    mkdir($dir);

    install_agent_user_fake_binary($dir, binary: 'id', exitCode: $idExitCode);
    install_agent_user_fake_binary($dir, binary: 'sudo', exitCode: $sudoExitCode);

    $path = getenv('PATH');
    putenv("PATH={$dir}:".($path === false ? '' : $path));

    return $dir;
}

function install_agent_user_fake_binary(string $dir, string $binary, int $exitCode): void
{
    file_put_contents("{$dir}/{$binary}", <<<BASH
        #!/usr/bin/env bash
        dir="\$(cd "\$(dirname "\$0")" && pwd)"
        printf '{$binary} %s\\n' "\$*" >>"\$dir/calls.log"
        exit {$exitCode}
        BASH);
    chmod(filename: "{$dir}/{$binary}", permissions: 0o755);
}

function delete_agent_user_fake_bin(string $path): void
{
    delete_agent_user_file("{$path}/id");
    delete_agent_user_file("{$path}/sudo");
    delete_agent_user_file("{$path}/calls.log");

    if (is_dir($path)) {
        rmdir($path);
    }
}

function delete_agent_user_file(string $path): void
{
    if (! is_file($path)) {
        return;
    }

    unlink($path);
}

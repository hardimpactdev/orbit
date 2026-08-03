<?php

declare(strict_types=1);

use App\Services\Nodes\LocalAgentAclEnsure;
use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;

describe('internal agent acl ensure command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        // Host workstations rarely have production agent paths; control
        // path existence while still executing real setfacl argv via PATH fakes.
        app()->instance(LocalAgentAclEnsure::class, new LocalAgentAclEnsure(
            directoryExists: static fn (string $path): bool => ! str_starts_with($path, '/home/orbit/orbit'),
            pathExists: static fn (string $path): bool => match ($path) {
                '/home/orbit/.config/orbit/config.json', '/home/orbit/.local/bin/orbit' => true,
                default => false,
            },
        ));
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        $originalPath = getenv('PATH');
        $_ENV['ORBIT_AGENT_ACL_ORIGINAL_PATH'] = $originalPath === false ? '' : $originalPath;
    });

    afterEach(function (): void {
        putenv('PATH='.($_ENV['ORBIT_AGENT_ACL_ORIGINAL_PATH'] ?? ''));
        app()->forgetInstance(LocalAgentAclEnsure::class);

        $fakeBinPaths = glob(sys_get_temp_dir().'/orbit-agent-acl-bin-*');

        if ($fakeBinPaths === false) {
            return;
        }

        foreach ($fakeBinPaths as $dir) {
            delete_agent_acl_fake_bin($dir);
        }
    });

    it('rejects a missing operation token before changing local state', function (): void {
        Artisan::call('internal:agent-acl:ensure', [
            '--json' => true,
        ]);

        expect(Artisan::output())
            ->json()
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('applies agent ACLs through fixed argv when setfacl already exists', function (): void {
        $bin = install_agent_acl_fake_bin(setfaclExitCode: 0, sudoExitCode: 0);

        $exitCode = Artisan::call('internal:agent-acl:ensure', [
            '--operation-token' => agent_acl_signed_operation_token(),
            '--json' => true,
        ]);
        $data = agent_acl_success_data();

        expect($exitCode)
            ->toBe(0)
            ->and($data)
            ->toMatchArray([
                'installed_acl' => false,
                'directory_acl_exit_code' => 0,
                'binary_acl_exit_code' => 0,
                'agent_binary_acl_exit_code' => 0,
            ])
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('setfacl --version')
            ->toContain(
                'sudo setfacl -m u:agent:--x /home/orbit /home/orbit/.config /home/orbit/.config/orbit /home/orbit/.local /home/orbit/.local/bin',
            )
            ->toContain(
                'sudo setfacl -m u:agent:r-- /home/orbit/.config/orbit/config.json',
            )
            ->toContain('sudo setfacl -m u:agent:r-x /home/orbit/.local/bin/orbit')
            // install.json is optional and applied only when present.
            ->not->toContain(
                'sudo setfacl -m u:agent:r-- /home/orbit/.config/orbit/config.json /home/orbit/.config/orbit/install.json',
            )
            // Optional checkout paths are not bulk-applied with the required set.
            ->not->toContain(
                'sudo setfacl -m u:agent:--x /home/orbit /home/orbit/orbit /home/orbit/orbit/bin',
            )
            ->not->toContain('apt-get update');
    });

    it('installs ACL support before applying agent ACLs when setfacl is missing', function (): void {
        $bin = install_agent_acl_fake_bin(setfaclExitCode: 127, sudoExitCode: 0);

        $exitCode = Artisan::call('internal:agent-acl:ensure', [
            '--operation-token' => agent_acl_signed_operation_token(),
            '--json' => true,
        ]);
        $data = agent_acl_success_data();

        expect($exitCode)
            ->toBe(0)
            ->and($data)
            ->toMatchArray([
                'installed_acl' => true,
            ])
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('setfacl --version')
            ->toContain('sudo apt-get update')
            ->toContain('sudo env DEBIAN_FRONTEND=noninteractive apt-get install -y acl')
            ->toContain(
                'sudo setfacl -m u:agent:--x /home/orbit /home/orbit/.config /home/orbit/.config/orbit /home/orbit/.local /home/orbit/.local/bin',
            )
            ->toContain(
                'sudo setfacl -m u:agent:r-- /home/orbit/.config/orbit/config.json',
            )
            ->toContain('sudo setfacl -m u:agent:r-x /home/orbit/.local/bin/orbit');
    });

    it('skips optional checkout paths when they are absent without failing', function (): void {
        $bin = install_agent_acl_fake_bin(setfaclExitCode: 0, sudoExitCode: 0);

        $exitCode = Artisan::call('internal:agent-acl:ensure', [
            '--operation-token' => agent_acl_signed_operation_token(),
            '--json' => true,
        ]);
        $data = agent_acl_success_data();
        $log = file_get_contents("{$bin}/calls.log");

        expect($exitCode)
            ->toBe(0)
            ->and($data['optional_directory_paths_skipped'] ?? null)
            ->toBeArray()
            // Required installed CLI path is always protected.
            ->and($log)
            ->toContain('sudo setfacl -m u:agent:r-x /home/orbit/.local/bin/orbit')
            // Absent optional checkout paths must not appear as bulk required targets.
            ->and($log)
            ->not->toContain(
                'sudo setfacl -m u:agent:--x /home/orbit /home/orbit/orbit /home/orbit/orbit/bin /home/orbit/.config',
            );
    });

    it('fails closed when required installed-path ACL application fails', function (): void {
        install_agent_acl_fake_bin(setfaclExitCode: 0, sudoExitCode: 1);

        $exitCode = Artisan::call('internal:agent-acl:ensure', [
            '--operation-token' => agent_acl_signed_operation_token(),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($payload['error']['code'] ?? null)
            ->not->toBeNull()->and(
                (string) ($payload['error']['message'] ?? $payload['error']['code'] ?? ''),
            )->toContain('stage=directory_acl')->and((string) json_encode($payload))
            ->not->toContain('optional_directory_acl');
    });
});

function agent_acl_signed_operation_token(
    string $id = 'agent-acl-ensure',
    string $node = 'agent',
    string $command = 'internal:agent-acl:ensure',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: agent_acl_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function agent_acl_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

/**
 * @return array<string, mixed>
 */
function agent_acl_success_data(): array
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

function install_agent_acl_fake_bin(int $setfaclExitCode, int $sudoExitCode): string
{
    $dir = sys_get_temp_dir().'/orbit-agent-acl-bin-'.bin2hex(random_bytes(8));
    mkdir($dir);

    install_agent_acl_fake_binary($dir, binary: 'setfacl', exitCode: $setfaclExitCode);
    install_agent_acl_fake_binary($dir, binary: 'sudo', exitCode: $sudoExitCode);

    $path = getenv('PATH');
    putenv("PATH={$dir}:".($path === false ? '' : $path));

    return $dir;
}

function install_agent_acl_fake_binary(string $dir, string $binary, int $exitCode): void
{
    file_put_contents("{$dir}/{$binary}", <<<BASH
        #!/usr/bin/env bash
        dir="\$(cd "\$(dirname "\$0")" && pwd)"
        printf '{$binary} %s\\n' "\$*" >>"\$dir/calls.log"
        exit {$exitCode}
        BASH);
    chmod(filename: "{$dir}/{$binary}", permissions: 0o755);
}

function delete_agent_acl_fake_bin(string $path): void
{
    delete_agent_acl_file("{$path}/setfacl");
    delete_agent_acl_file("{$path}/sudo");
    delete_agent_acl_file("{$path}/calls.log");

    if (is_dir($path)) {
        rmdir($path);
    }
}

function delete_agent_acl_file(string $path): void
{
    if (! is_file($path)) {
        return;
    }

    unlink($path);
}

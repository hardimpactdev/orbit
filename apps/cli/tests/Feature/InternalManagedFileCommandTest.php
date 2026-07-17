<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal managed file command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance(\App\Services\Executor\OperationTokenGuard::class);
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        $hostPathPrefix = getenv('ORBIT_HOST_PATH_PREFIX');
        $this->originalHostPathPrefix = is_string($hostPathPrefix) ? $hostPathPrefix : null;
    });

    afterEach(function (): void {
        $originalPath = getenv('ORBIT_MANAGED_FILE_ORIGINAL_PATH');

        if (is_string($originalPath) && $originalPath !== '') {
            putenv("PATH={$originalPath}");
            putenv('ORBIT_MANAGED_FILE_ORIGINAL_PATH');
        }

        $this->originalHostPathPrefix === null
            ? putenv('ORBIT_HOST_PATH_PREFIX')
            : putenv("ORBIT_HOST_PATH_PREFIX={$this->originalHostPathPrefix}");
    });

    it('rejects a missing operation token before reading stdin', function (): void {
        [$exitCode, $output] = run_internal_managed_file_command([
            'action' => 'probe',
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

    it('rejects paths outside managed roots after token validation', function (): void {
        [$exitCode, $output] = run_internal_managed_file_command(
            [
                'action' => 'probe',
                '--operation-token' => managed_file_signed_operation_token(),
                '--json' => true,
            ],
            json_encode(['path' => '/etc/passwd'], JSON_THROW_ON_ERROR),
        );

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toContain('"code":"validation_failed"')
            ->and($output)
            ->toContain('"message":"Managed file path is invalid."');
    });

    it('rejects path traversal inside managed roots after token validation', function (): void {
        [$exitCode, $output] = run_internal_managed_file_command(
            [
                'action' => 'probe',
                '--operation-token' => managed_file_signed_operation_token(),
                '--json' => true,
            ],
            json_encode(['path' => '/Users/nckrtl/.config/orbit/../.ssh/id_rsa'], JSON_THROW_ON_ERROR),
        );

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toContain('"code":"validation_failed"')
            ->and($output)
            ->toContain('"message":"Managed file path is invalid."');
    });

    it('allows user orbit roots after token validation', function (string $path): void {
        fake_managed_file_sudo_binary(pathType: 'missing');

        [$exitCode, $output] = run_internal_managed_file_command(
            [
                'action' => 'probe',
                '--operation-token' => managed_file_signed_operation_token(),
                '--json' => true,
            ],
            json_encode(['path' => $path], JSON_THROW_ON_ERROR),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('"exists":false');
    })->with([
        '/Users/orbit-test-user/.config/orbit/ca/root.crt',
        '/Users/orbit-test-user/.local/share/orbit/caddy/data',
        '/home/orbit-test-user/.config/orbit/ca/root.crt',
        '/home/orbit-test-user/.local/share/orbit/caddy/data',
    ]);

    it('probes managed file state through fixed argv commands', function (): void {
        $path = '/etc/orbit/managed-file-probe-'.bin2hex(random_bytes(8));
        $log = fake_managed_file_sudo_binary(path: $path);

        [$exitCode, $output] = run_internal_managed_file_command(
            [
                'action' => 'probe',
                '--operation-token' => managed_file_signed_operation_token(),
                '--json' => true,
            ],
            json_encode(['path' => $path], JSON_THROW_ON_ERROR),
        );

        $commands = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        expect(is_array($commands))->toBeTrue();
        /** @var list<string> $commands */

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('"exists":true')
            ->and($output)
            ->toContain('"hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"')
            ->and($output)
            ->toContain('"mode":"644"')
            ->and($commands)
            ->toContain("-n test -f {$path}")
            ->toContain("-n sha256sum {$path}")
            ->toContain("-n stat -c %a {$path}");
    });

    it('writes managed file content through fixed argv commands', function (): void {
        $log = fake_managed_file_sudo_binary();

        [$exitCode, $output] = run_internal_managed_file_command(
            [
                'action' => 'write',
                '--operation-token' => managed_file_signed_operation_token(),
                '--json' => true,
            ],
            json_encode([
                'path' => '/etc/apt/apt.conf.d/20auto-upgrades',
                'content' => "APT::Periodic::Update-Package-Lists \"1\";\n",
                'mode' => '0644',
                'directory_mode' => '0755',
            ], JSON_THROW_ON_ERROR),
        );

        $commands = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        expect(is_array($commands))->toBeTrue();
        /** @var list<string> $commands */

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('"path":"/etc/apt/apt.conf.d/20auto-upgrades"')
            ->and($commands)
            ->toContain('-n install -d -m 0755 /etc/apt/apt.conf.d')
            ->and($commands)
            ->toContain('-n tee /etc/apt/apt.conf.d/20auto-upgrades')
            ->and($commands)
            ->toContain('-n chmod 0644 /etc/apt/apt.conf.d/20auto-upgrades');
    });

    it('maps gateway host paths through the mounted host path prefix', function (): void {
        putenv('ORBIT_HOST_PATH_PREFIX=/mnt/orbit-host');
        $log = fake_managed_file_sudo_binary(pathType: 'missing');

        [$exitCode, $output] = run_internal_managed_file_command(
            [
                'action' => 'write',
                '--operation-token' => managed_file_signed_operation_token(),
                '--json' => true,
            ],
            json_encode([
                'path' => '/etc/orbit/certs/s3.orbit.crt',
                'content' => "certificate\n",
                'mode' => '0644',
                'directory_mode' => '0755',
            ], JSON_THROW_ON_ERROR),
        );

        $commands = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        expect($commands)->toBeArray();
        /** @var list<string> $commands */

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('"path":"/mnt/orbit-host/etc/orbit/certs/s3.orbit.crt"')
            ->and($commands)
            ->toContain('-n install -d -m 0755 /mnt/orbit-host/etc/orbit/certs')
            ->toContain('-n tee /mnt/orbit-host/etc/orbit/certs/s3.orbit.crt')
            ->toContain('-n chmod 0644 /mnt/orbit-host/etc/orbit/certs/s3.orbit.crt')
            ->not->toContain('-n tee /etc/orbit/certs/s3.orbit.crt');
    });

    it('rejects an invalid mounted host path prefix', function (string $prefix): void {
        putenv("ORBIT_HOST_PATH_PREFIX={$prefix}");

        [$exitCode, $output] = run_internal_managed_file_command(
            [
                'action' => 'probe',
                '--operation-token' => managed_file_signed_operation_token(),
                '--json' => true,
            ],
            json_encode(['path' => '/etc/orbit/certs/s3.orbit.crt'], JSON_THROW_ON_ERROR),
        );

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toContain('"code":"managed_file.host_path_invalid"')
            ->toContain('"message":"Managed file host path mapping is invalid."');
    })->with(['mnt/orbit-host', '/mnt/orbit-host/../escape']);

    it('replaces an existing empty directory at the managed file path', function (): void {
        $path = '/srv/orbit/s3/config/s3.json';
        $log = fake_managed_file_sudo_binary(path: $path, pathType: 'directory');

        [$exitCode] = run_internal_managed_file_command(
            [
                'action' => 'write',
                '--operation-token' => managed_file_signed_operation_token(),
                '--json' => true,
            ],
            json_encode([
                'path' => $path,
                'content' => "{\n  \"identities\": []\n}\n",
                'mode' => '0600',
                'directory_mode' => '0750',
            ], JSON_THROW_ON_ERROR),
        );

        $commands = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        expect(is_array($commands))->toBeTrue();
        /** @var list<string> $commands */

        expect($exitCode)
            ->toBe(0)
            ->and($commands)
            ->toContain("-n test -d {$path}")
            ->toContain("-n rmdir -- {$path}")
            ->toContain("-n tee {$path}")
            ->toContain("-n chmod 0600 {$path}");
    });

    it('writes user orbit files without sudo', function (): void {
        $log = fake_user_managed_file_binaries();

        [$exitCode, $output] = run_internal_managed_file_command(
            [
                'action' => 'write',
                '--operation-token' => managed_file_signed_operation_token(),
                '--json' => true,
            ],
            json_encode([
                'path' => '/Users/orbit-test-user/.config/orbit/certs/app.test.crt',
                'content' => "certificate\n",
                'mode' => '0644',
                'directory_mode' => '0755',
            ], JSON_THROW_ON_ERROR),
        );

        $commands = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        expect(is_array($commands))->toBeTrue();
        /** @var list<string> $commands */

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('"path":"/Users/orbit-test-user/.config/orbit/certs/app.test.crt"')
            ->and($commands)
            ->toContain('install -d -m 0755 /Users/orbit-test-user/.config/orbit/certs')
            ->toContain('tee /Users/orbit-test-user/.config/orbit/certs/app.test.crt')
            ->toContain('chmod 0644 /Users/orbit-test-user/.config/orbit/certs/app.test.crt')
            ->not->toContain('sudo');
    });
});

function managed_file_signed_operation_token(
    string $id = 'managed-file',
    string $node = 'app-dev',
    string $command = 'internal:managed-file',
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
function run_internal_managed_file_command(array $parameters = [], string $stdin = ''): array
{
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput($parameters);
    $input->setStream($stream);

    $commands = Artisan::all();
    /** @var SymfonyCommand|null $command */
    $command = $commands['internal:managed-file'] ?? null;

    if (! $command instanceof SymfonyCommand) {
        throw new RuntimeException('Internal managed file command is not registered.');
    }

    $output = new BufferedOutput;
    $exitCode = $command->run($input, $output);

    return [$exitCode, trim($output->fetch())];
}

function fake_managed_file_sudo_binary(
    string $path = '/etc/apt/apt.conf.d/20auto-upgrades',
    string $pathType = 'file',
): string {
    $directory = sys_get_temp_dir().'/orbit-managed-file-'.bin2hex(random_bytes(8));
    mkdir("{$directory}/bin", recursive: true);
    $log = "{$directory}/commands.log";
    $fileExists = $pathType === 'file' ? '1' : '0';
    $directoryExists = $pathType === 'directory' ? '1' : '0';

    $script = <<<SH
        #!/bin/sh
        printf '%s\n' "\$*" >> '$log'

        if [ "\$1" = "-n" ]; then
          shift
        fi

        if [ "\$1" = "test" ]; then
          [ "\$2" = "-f" ] && [ '$fileExists' = '1' ] && exit 0
          [ "\$2" = "-d" ] && [ '$directoryExists' = '1' ] && exit 0
          exit 1
        fi

        if [ "\$1" = "sha256sum" ]; then
          echo 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa  {$path}'
          exit 0
        fi

        if [ "\$1" = "stat" ]; then
          echo '644'
          exit 0
        fi

        if [ "\$1" = "install" ] || [ "\$1" = "chmod" ] || [ "\$1" = "rmdir" ]; then
          exit 0
        fi

        if [ "\$1" = "tee" ]; then
          cat >/dev/null
          exit 0
        fi

        exit 64
        SH;

    file_put_contents("{$directory}/bin/sudo", $script);
    chmod("{$directory}/bin/sudo", 0755);

    $originalPath = getenv('PATH') ?: '';
    putenv("ORBIT_MANAGED_FILE_ORIGINAL_PATH={$originalPath}");
    putenv("PATH={$directory}/bin:{$originalPath}");

    return $log;
}

function fake_user_managed_file_binaries(): string
{
    $directory = sys_get_temp_dir().'/orbit-user-managed-file-'.bin2hex(random_bytes(8));
    mkdir("{$directory}/bin", recursive: true);
    $log = "{$directory}/commands.log";

    foreach (['install', 'tee', 'chmod'] as $command) {
        $script = <<<SH
            #!/bin/sh
            printf '%s %s\n' '$command' "\$*" >> '$log'
            [ '$command' = 'tee' ] && cat >/dev/null
            exit 0
            SH;

        file_put_contents("{$directory}/bin/{$command}", $script);
        chmod("{$directory}/bin/{$command}", 0755);
    }

    file_put_contents("{$directory}/bin/sudo", "#!/bin/sh\nprintf 'sudo\\n' >> '$log'\nexit 77\n");
    chmod("{$directory}/bin/sudo", 0755);

    $originalPath = getenv('PATH') ?: '';
    putenv("ORBIT_MANAGED_FILE_ORIGINAL_PATH={$originalPath}");
    putenv("PATH={$directory}/bin:{$originalPath}");

    return $log;
}

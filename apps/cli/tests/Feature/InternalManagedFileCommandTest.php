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
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    afterEach(function (): void {
        $originalPath = getenv('ORBIT_MANAGED_FILE_ORIGINAL_PATH');

        if (is_string($originalPath) && $originalPath !== '') {
            putenv("PATH={$originalPath}");
            putenv('ORBIT_MANAGED_FILE_ORIGINAL_PATH');
        }
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

    it('probes managed file state through fixed argv commands', function (): void {
        fake_managed_file_sudo_binary(fileExists: true);

        [$exitCode, $output] = run_internal_managed_file_command(
            [
                'action' => 'probe',
                '--operation-token' => managed_file_signed_operation_token(),
                '--json' => true,
            ],
            json_encode(['path' => '/etc/apt/apt.conf.d/20auto-upgrades'], JSON_THROW_ON_ERROR),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('"exists":true')
            ->and($output)
            ->toContain('"hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"')
            ->and($output)
            ->toContain('"mode":"644"');
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

function fake_managed_file_sudo_binary(bool $fileExists = true): string
{
    $directory = sys_get_temp_dir().'/orbit-managed-file-'.bin2hex(random_bytes(8));
    mkdir("{$directory}/bin", recursive: true);
    $log = "{$directory}/commands.log";
    $exists = $fileExists ? '1' : '0';

    $script = <<<SH
        #!/bin/sh
        printf '%s\n' "\$*" >> '$log'

        if [ "\$1" = "-n" ]; then
          shift
        fi

        if [ "\$1" = "test" ]; then
          [ '$exists' = '1' ] && exit 0
          exit 1
        fi

        if [ "\$1" = "sha256sum" ]; then
          echo 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa  /etc/apt/apt.conf.d/20auto-upgrades'
          exit 0
        fi

        if [ "\$1" = "stat" ]; then
          echo '644'
          exit 0
        fi

        if [ "\$1" = "install" ] || [ "\$1" = "chmod" ]; then
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

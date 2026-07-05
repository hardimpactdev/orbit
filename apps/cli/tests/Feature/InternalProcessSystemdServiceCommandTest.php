<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal process systemd service command', function (): void {
    beforeEach(function (): void {
        configure_process_systemd_service_operation_token_guard();
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    it('rejects a missing operation token before running systemctl', function (): void {
        $exitCode = Artisan::call('internal:process-systemd-service', [
            'action' => 'start',
            'service' => 'opencode-server.service',
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('rejects invalid service names after token validation', function (): void {
        $exitCode = Artisan::call('internal:process-systemd-service', [
            'action' => 'start',
            'service' => '../bad.service',
            '--operation-token' => process_systemd_service_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Systemd service name is invalid.',
                ['field' => 'service'],
            ));
    });

    it('requires a validated unit path for remove actions', function (): void {
        $exitCode = Artisan::call('internal:process-systemd-service', [
            'action' => 'remove',
            'service' => 'opencode-server.service',
            'unit-path' => '/tmp/opencode-server.service',
            '--operation-token' => process_systemd_service_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Systemd unit path is invalid.',
                ['field' => 'unit-path'],
            ));
    });

    it('requires unit content for apply actions', function (): void {
        $exitCode = Artisan::call('internal:process-systemd-service', [
            'action' => 'apply',
            'service' => 'opencode-server.service',
            '--operation-token' => process_systemd_service_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Systemd service content is invalid.',
                ['field' => 'content'],
            ));
    });

    it('probes systemd service state through fixed argv commands', function (): void {
        fake_process_systemd_service_sudo_binary();

        [$exitCode, $output] = run_internal_process_systemd_service_command([
            'action' => 'probe',
            'service' => 'opencode-server.service',
            '--operation-token' => process_systemd_service_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('"action":"probe"')
            ->and($output)
            ->toContain('"service":"opencode-server.service"')
            ->and($output)
            ->toContain('"exists":true')
            ->and($output)
            ->toContain('"enabled":true')
            ->and($output)
            ->toContain('"hash":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"');
    });
});

function configure_process_systemd_service_operation_token_guard(): void
{
    app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
}

function process_systemd_service_signed_operation_token(
    string $id = 'process-systemd-service',
    string $node = 'app-dev',
    string $command = 'internal:process-systemd-service',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: process_systemd_service_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function process_systemd_service_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function run_internal_process_systemd_service_command(array $parameters): array
{
    $input = new ArrayInput($parameters);
    $output = new BufferedOutput;
    $commands = Artisan::all();
    /** @var SymfonyCommand|null $command */
    $command = $commands['internal:process-systemd-service'] ?? null;

    if (! $command instanceof SymfonyCommand) {
        throw new RuntimeException('Internal process systemd service command is not registered.');
    }

    $exitCode = $command->run($input, $output);

    return [$exitCode, trim($output->fetch())];
}

function fake_process_systemd_service_sudo_binary(): void
{
    $directory = sys_get_temp_dir().'/orbit-process-systemd-service-'.bin2hex(random_bytes(8));
    mkdir("{$directory}/bin", recursive: true);

    $script = <<<'SH'
        #!/bin/sh
        if [ "$1" = "systemctl" ] && [ "$2" = "is-enabled" ]; then
          echo enabled
          exit 0
        fi

        if [ "$1" = "test" ]; then
          exit 0
        fi

        if [ "$1" = "sha256sum" ]; then
          echo 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa  /etc/systemd/system/opencode-server.service'
          exit 0
        fi

        exit 64
        SH;

    file_put_contents("{$directory}/bin/sudo", $script);
    chmod("{$directory}/bin/sudo", 0755);
    putenv('PATH='.$directory.'/bin:'.(getenv('PATH') ?: ''));
}

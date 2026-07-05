<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal WireGuard endpoint rotate command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    afterEach(function (): void {
        $originalPath = getenv('ORBIT_WIREGUARD_ENDPOINT_ROTATE_ORIGINAL_PATH');

        if (is_string($originalPath) && $originalPath !== '') {
            putenv("PATH={$originalPath}");
            putenv('ORBIT_WIREGUARD_ENDPOINT_ROTATE_ORIGINAL_PATH');
        }
    });

    it('rejects a missing operation token before mutating wireguard config', function (): void {
        [$exitCode, $output] = run_internal_wireguard_endpoint_rotate_command([
            'endpoint' => '10.3.0.2:51820',
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

    it('rejects invalid endpoints after token validation', function (): void {
        [$exitCode, $output] = run_internal_wireguard_endpoint_rotate_command([
            'endpoint' => 'not a host',
            '--operation-token' => wireguard_endpoint_rotate_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'WireGuard endpoint must include a port.',
                ['field' => 'endpoint'],
            ));
    });

    it('rotates the config endpoint and active peers through fixed argv commands', function (): void {
        $log = fake_wireguard_endpoint_rotate_binaries(peers: ['peer-a', 'peer-b']);

        [$exitCode, $output] = run_internal_wireguard_endpoint_rotate_command([
            'endpoint' => '10.3.0.2:51820',
            '--operation-token' => wireguard_endpoint_rotate_signed_operation_token(),
            '--json' => true,
        ]);

        $commands = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        expect(is_array($commands))->toBeTrue();
        /** @var list<string> $commands */

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('"endpoint":"10.3.0.2:51820"')
            ->and($output)
            ->toContain('"config_path":"/etc/wireguard/wg-orbit.conf"')
            ->and($output)
            ->toContain('"interface":"wg-orbit"')
            ->and($output)
            ->toContain('"peers_updated":2')
            ->and($output)
            ->toContain('"backup_path":"/etc/wireguard/wg-orbit.conf.before-gateway-endpoint-')
            ->and($commands)
            ->toContain('test -f /etc/wireguard/wg-orbit.conf')
            ->and($commands)
            ->toContain('grep -qE ^Endpoint[[:space:]]*= /etc/wireguard/wg-orbit.conf')
            ->and($commands)
            ->toContain('sed -i -E s#^Endpoint[[:space:]]*=.*#Endpoint = 10.3.0.2:51820# /etc/wireguard/wg-orbit.conf')
            ->and($commands)
            ->toContain('wg show wg-orbit peers')
            ->and($commands)
            ->toContain('wg set wg-orbit peer peer-a endpoint 10.3.0.2:51820')
            ->and($commands)
            ->toContain('wg set wg-orbit peer peer-b endpoint 10.3.0.2:51820');
    });

    it('fails when neither supported wireguard config path exists', function (): void {
        fake_wireguard_endpoint_rotate_binaries(configExists: false);

        [$exitCode, $output] = run_internal_wireguard_endpoint_rotate_command([
            'endpoint' => '10.3.0.2:51820',
            '--operation-token' => wireguard_endpoint_rotate_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'wireguard_config_missing',
                'No WireGuard config file found for endpoint rotation.',
            ));
    });
});

function wireguard_endpoint_rotate_signed_operation_token(
    string $id = 'wireguard-endpoint-rotate',
    string $node = 'app-dev',
    string $command = 'internal:wireguard-endpoint:rotate',
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
function run_internal_wireguard_endpoint_rotate_command(array $parameters): array
{
    $output = new BufferedOutput;
    $commands = Artisan::all();
    /** @var mixed $command */
    $command = $commands['internal:wireguard-endpoint:rotate'] ?? null;

    if (! $command instanceof SymfonyCommand) {
        throw new RuntimeException('Internal WireGuard endpoint rotate command is not registered.');
    }

    $exitCode = $command->run(new ArrayInput($parameters), $output);

    return [$exitCode, trim($output->fetch())];
}

/**
 * @param  list<string>  $peers
 */
function fake_wireguard_endpoint_rotate_binaries(bool $configExists = true, array $peers = []): string
{
    $directory = sys_get_temp_dir().'/orbit-wireguard-endpoint-rotate-'.bin2hex(random_bytes(8));
    mkdir("{$directory}/bin", recursive: true);
    $log = "{$directory}/commands.log";
    $peerOutput = implode("\n", $peers);

    $script = <<<SH
        #!/bin/sh
        printf '%s\n' "\$*" >> '$log'

        if [ "\$1" = "test" ] && [ "\$2" = "-f" ]; then
          if [ '$configExists' = '1' ] && [ "\$3" = "/etc/wireguard/wg-orbit.conf" ]; then
            exit 0
          fi
          exit 1
        fi

        if [ "\$1" = "grep" ]; then
          exit 0
        fi

        if [ "\$1" = "cp" ] || [ "\$1" = "sed" ]; then
          exit 0
        fi

        if [ "\$1" = "wg" ] && [ "\$2" = "show" ]; then
          printf '%s\n' '$peerOutput'
          exit 0
        fi

        if [ "\$1" = "wg" ] && [ "\$2" = "set" ]; then
          exit 0
        fi

        exit 64
        SH;
    file_put_contents("{$directory}/bin/sudo", $script);
    chmod("{$directory}/bin/sudo", 0755);

    $originalPath = getenv('PATH') ?: '';
    putenv("ORBIT_WIREGUARD_ENDPOINT_ROTATE_ORIGINAL_PATH={$originalPath}");
    putenv("PATH={$directory}/bin:{$originalPath}");

    return $log;
}

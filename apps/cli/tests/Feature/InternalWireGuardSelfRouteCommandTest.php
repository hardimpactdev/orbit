<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal WireGuard self route command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    afterEach(function (): void {
        if (isset($this->wireGuardSelfRouteOriginalPath)) {
            putenv("PATH={$this->wireGuardSelfRouteOriginalPath}");
            unset($this->wireGuardSelfRouteOriginalPath);
        }

        putenv('ORBIT_FAKE_IP_OUTPUT');
    });

    it('rejects a missing operation token before inspecting routes', function (): void {
        [$exitCode, $output] = run_internal_wireguard_self_route_command([
            'address' => '10.6.0.4',
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

    it('rejects invalid addresses after token validation', function (): void {
        [$exitCode, $output] = run_internal_wireguard_self_route_command([
            'address' => 'not-an-ip',
            '--operation-token' => wireguard_self_route_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'WireGuard address must be a valid IP address.',
                ['field' => 'address'],
            ));
    });

    it('emits the local route command exit code and output', function (): void {
        fake_wireguard_self_route_ip_binary($this, "local 10.6.0.4 dev lo src 10.6.0.4 uid 1000\n");

        [$exitCode, $output] = run_internal_wireguard_self_route_command([
            'address' => '10.6.0.4',
            '--operation-token' => wireguard_self_route_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::success([
                'address' => '10.6.0.4',
                'command' => 'ip route get 10.6.0.4',
                'exit_code' => 0,
                'output' => 'local 10.6.0.4 dev lo src 10.6.0.4 uid 1000',
            ]));
    });
});

function wireguard_self_route_signed_operation_token(
    string $id = 'wireguard-self-route',
    string $node = 'app-dev',
    string $command = 'internal:wireguard-self-route',
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
function run_internal_wireguard_self_route_command(array $parameters): array
{
    $output = new BufferedOutput;
    $exitCode = Artisan::all()['internal:wireguard-self-route']->run(new ArrayInput($parameters), $output);

    return [$exitCode, trim($output->fetch())];
}

function fake_wireguard_self_route_ip_binary(object $test, string $output): void
{
    $directory = sys_get_temp_dir().'/orbit-wireguard-self-route-'.bin2hex(random_bytes(8));
    mkdir("{$directory}/bin", recursive: true);
    file_put_contents("{$directory}/output", $output);
    $outputPath = "{$directory}/output";

    $script = <<<SH
        #!/bin/sh
        if [ "$1" != "route" ] || [ "$2" != "get" ]; then
          exit 64
        fi
        cat '$outputPath'
        SH;
    file_put_contents("{$directory}/bin/ip", $script);
    chmod("{$directory}/bin/ip", 0755);

    $test->wireGuardSelfRouteOriginalPath = getenv('PATH') ?: '';
    putenv("PATH={$directory}/bin:{$test->wireGuardSelfRouteOriginalPath}");
}

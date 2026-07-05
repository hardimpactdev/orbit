<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;

describe('internal WireGuard interface public key read command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        $path = getenv('PATH');
        putenv('ORBIT_WIREGUARD_PUBLIC_KEY_ORIGINAL_PATH='.($path === false ? '' : $path));
    });

    afterEach(function (): void {
        $path = getenv('ORBIT_WIREGUARD_PUBLIC_KEY_ORIGINAL_PATH');
        putenv('PATH='.($path === false ? '' : $path));
        putenv('ORBIT_WIREGUARD_PUBLIC_KEY_ORIGINAL_PATH');
    });

    it('rejects a missing operation token before reading the interface', function (): void {
        Artisan::call('internal:wireguard-interface-public-key:read', [
            '--json' => true,
        ]);

        expect(Artisan::output())
            ->json()
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('reads the local WireGuard interface public key through fixed argv', function (): void {
        $bin = fake_wireguard_public_key_sudo_binary("interface-public-key\n");

        $exitCode = Artisan::call('internal:wireguard-interface-public-key:read', [
            '--operation-token' => wireguard_public_key_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and(Artisan::output())
            ->json()
            ->toBe(JsonEnvelope::success([
                'public_key' => 'interface-public-key',
            ]))
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('sudo wg show wg-orbit public-key');
    });
});

function wireguard_public_key_signed_operation_token(
    string $id = 'wireguard-public-key',
    string $node = 'app-dev',
    string $command = 'internal:wireguard-interface-public-key:read',
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

function fake_wireguard_public_key_sudo_binary(string $output): string
{
    $dir = sys_get_temp_dir().'/orbit-wireguard-public-key-bin-'.bin2hex(random_bytes(8));
    mkdir($dir);
    $encodedOutput = base64_encode($output);
    file_put_contents("{$dir}/sudo", <<<PHP
        #!/usr/bin/env php
        <?php
        file_put_contents(__DIR__.'/calls.log', basename(\$argv[0]).' '.implode(' ', array_slice(\$argv, 1)).PHP_EOL, FILE_APPEND);
        echo base64_decode('{$encodedOutput}', true);
        PHP);
    chmod(filename: "{$dir}/sudo", permissions: 0o755);

    $path = getenv('PATH');
    putenv("PATH={$dir}:".($path === false ? '' : $path));

    return $dir;
}

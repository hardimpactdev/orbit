<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;

describe('internal firewall rule probe command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        $originalPath = getenv('PATH');
        $_ENV['ORBIT_FIREWALL_RULE_PROBE_ORIGINAL_PATH'] = $originalPath === false ? '' : $originalPath;
    });

    afterEach(function (): void {
        putenv('PATH='.($_ENV['ORBIT_FIREWALL_RULE_PROBE_ORIGINAL_PATH'] ?? ''));

        $fakeBinPaths = glob(sys_get_temp_dir().'/orbit-firewall-rule-probe-bin-*');

        if ($fakeBinPaths === false) {
            return;
        }

        foreach ($fakeBinPaths as $dir) {
            delete_firewall_rule_probe_fake_bin($dir);
        }
    });

    it('rejects a missing operation token before probing local state', function (): void {
        Artisan::call('internal:firewall-rule:probe', [
            '--json' => true,
        ]);

        expect(Artisan::output())
            ->json()
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('probes UFW status and stored rule files through fixed argv', function (): void {
        $bin = install_firewall_rule_probe_fake_bin();

        $exitCode = Artisan::call('internal:firewall-rule:probe', [
            '--operation-token' => firewall_rule_probe_signed_operation_token(),
            '--json' => true,
        ]);
        $data = firewall_rule_probe_success_data();

        expect($exitCode)
            ->toBe(0)
            ->and($data['output'] ?? null)
            ->toBe(
                "Status: active\n[ 1] 5173/tcp ALLOW IN 10.6.0.0/24\n__orbit_ufw_file:user:-A ufw-user-input -p tcp --dport 5173 -s 10.6.0.0/24 -j ACCEPT\n",
            )
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('sudo ufw status numbered')
            ->toContain('sudo awk');
    });
});

function firewall_rule_probe_signed_operation_token(
    string $id = 'firewall-rule-probe',
    string $node = 'app-dev',
    string $command = 'internal:firewall-rule:probe',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: firewall_rule_probe_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function firewall_rule_probe_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

/**
 * @return array<string, mixed>
 */
function firewall_rule_probe_success_data(): array
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

function install_firewall_rule_probe_fake_bin(): string
{
    $dir = sys_get_temp_dir().'/orbit-firewall-rule-probe-bin-'.bin2hex(random_bytes(8));
    mkdir($dir);

    file_put_contents("{$dir}/sudo", <<<'PHP'
        #!/usr/bin/env php
        <?php
        file_put_contents(__DIR__.'/calls.log', basename($argv[0]).' '.implode(' ', array_slice($argv, 1)).PHP_EOL, FILE_APPEND);

        if (($argv[1] ?? null) === 'ufw') {
            echo "Status: active\n[ 1] 5173/tcp ALLOW IN 10.6.0.0/24\n";
            exit(0);
        }

        if (($argv[1] ?? null) === 'awk') {
            echo "__orbit_ufw_file:user:-A ufw-user-input -p tcp --dport 5173 -s 10.6.0.0/24 -j ACCEPT\n";
            exit(0);
        }

        exit(1);
        PHP);
    chmod(filename: "{$dir}/sudo", permissions: 0o755);

    $path = getenv('PATH');
    putenv("PATH={$dir}:".($path === false ? '' : $path));

    return $dir;
}

function delete_firewall_rule_probe_fake_bin(string $path): void
{
    foreach (['sudo', 'calls.log'] as $file) {
        $fullPath = "{$path}/{$file}";

        if (is_file($fullPath)) {
            unlink($fullPath);
        }
    }

    if (is_dir($path)) {
        rmdir($path);
    }
}

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;

describe('internal doctor self command', function (): void {
    beforeEach(function (): void {
        $this->previousBinPath = getenv('ORBIT_BIN_PATH');
        $this->previousArgvPath = $_SERVER['argv'][0] ?? null;
        putenv('ORBIT_BIN_PATH');
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    afterEach(function (): void {
        $this->previousBinPath === false
            ? putenv('ORBIT_BIN_PATH')
            : putenv("ORBIT_BIN_PATH={$this->previousBinPath}");

        if ($this->previousArgvPath === null) {
            unset($_SERVER['argv'][0]);
        } else {
            $_SERVER['argv'][0] = $this->previousArgvPath;
        }

        $fakeBinPaths = glob(sys_get_temp_dir().'/orbit-doctor-self-bin-*');

        if ($fakeBinPaths === false) {
            return;
        }

        foreach ($fakeBinPaths as $dir) {
            delete_doctor_self_fake_bin($dir);
        }
    });

    it('rejects a missing operation token before running doctor', function (): void {
        Artisan::call('internal:doctor-self', [
            '--json' => true,
        ]);

        expect(Artisan::output())
            ->json()
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('falls back to the configured binary when the invoked launcher is unavailable', function (): void {
        $bin = install_doctor_self_fake_bin();
        config()->set('orbit.local_executor_binary', "{$bin}/orbit");
        $_SERVER['argv'][0] = '';

        $exitCode = Artisan::call('internal:doctor-self', [
            '--operation-token' => doctor_self_signed_operation_token(),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data'] ?? [])
            ->toMatchArray([
                'exit_code' => 0,
                'stderr' => '',
            ])
            ->and($payload['success']['data']['output'] ?? '')
            ->toContain('doctor.node.done')
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('orbit doctor --self --stream-json');
    });

    it('runs doctor through the invoked launcher instead of a stale configured binary', function (): void {
        $configuredBin = install_doctor_self_fake_bin();
        $invokedBin = install_doctor_self_fake_bin();
        config()->set('orbit.local_executor_binary', "{$configuredBin}/orbit");
        $_SERVER['argv'][0] = "{$invokedBin}/orbit";

        $exitCode = Artisan::call('internal:doctor-self', [
            '--operation-token' => doctor_self_signed_operation_token(),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data']['exit_code'] ?? null)
            ->toBe(0)
            ->and(file_exists("{$invokedBin}/calls.log"))
            ->toBeTrue()
            ->and(file_exists("{$configuredBin}/calls.log"))
            ->toBeFalse()
            ->and(file_get_contents("{$invokedBin}/calls.log"))
            ->toContain('orbit doctor --self --stream-json');
    });

    it('prefers the token-bound Orbit binary over invoked and configured fallbacks', function (): void {
        $configuredBin = install_doctor_self_fake_bin();
        $invokedBin = install_doctor_self_fake_bin();
        $operationBin = install_doctor_self_fake_bin();
        config()->set('orbit.local_executor_binary', "{$configuredBin}/orbit");
        $_SERVER['argv'][0] = "{$invokedBin}/orbit";
        putenv("ORBIT_BIN_PATH={$operationBin}/orbit");

        $exitCode = Artisan::call('internal:doctor-self', [
            '--operation-token' => doctor_self_signed_operation_token(),
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data']['exit_code'] ?? null)
            ->toBe(0)
            ->and(file_exists("{$operationBin}/calls.log"))
            ->toBeTrue()
            ->and(file_exists("{$invokedBin}/calls.log"))
            ->toBeFalse()
            ->and(file_exists("{$configuredBin}/calls.log"))
            ->toBeFalse()
            ->and(file_get_contents("{$operationBin}/calls.log"))
            ->toContain('orbit doctor --self --stream-json');
    });
});

function doctor_self_signed_operation_token(
    string $id = 'doctor-self',
    string $node = 'app-dev',
    string $command = 'internal:doctor-self',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: doctor_self_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function doctor_self_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

function install_doctor_self_fake_bin(): string
{
    $dir = sys_get_temp_dir().'/orbit-doctor-self-bin-'.bin2hex(random_bytes(8));
    mkdir($dir);

    file_put_contents("{$dir}/orbit", <<<'PHP'
        #!/usr/bin/env php
        <?php
        file_put_contents(__DIR__.'/calls.log', basename($argv[0]).' '.implode(' ', array_slice($argv, 1)).PHP_EOL, FILE_APPEND);
        echo json_encode(['event' => 'doctor.node.start']).PHP_EOL;
        echo json_encode(['event' => 'doctor.node.done', 'data' => ['doctor' => ['summary' => ['issues' => 0]]]]).PHP_EOL;
        exit(0);
        PHP);
    chmod(filename: "{$dir}/orbit", permissions: 0o755);

    return $dir;
}

function delete_doctor_self_fake_bin(string $path): void
{
    delete_doctor_self_file("{$path}/orbit");
    delete_doctor_self_file("{$path}/calls.log");

    if (is_dir($path)) {
        rmdir($path);
    }
}

function delete_doctor_self_file(string $path): void
{
    if (! is_file($path)) {
        return;
    }

    unlink($path);
}

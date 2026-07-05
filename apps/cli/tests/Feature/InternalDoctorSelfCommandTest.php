<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;

describe('internal doctor self command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    afterEach(function (): void {
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

    it('runs local doctor self through fixed argv', function (): void {
        $bin = install_doctor_self_fake_bin();
        config()->set('orbit.local_executor_binary', "{$bin}/orbit");

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

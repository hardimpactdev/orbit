<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal process logs command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        $originalPath = getenv('PATH');
        $this->originalPath = $originalPath === false ? '' : $originalPath;
    });

    afterEach(function (): void {
        putenv("PATH={$this->originalPath}");

        $fakeBinPaths = glob(sys_get_temp_dir().'/orbit-process-logs-bin-*');

        if ($fakeBinPaths === false) {
            return;
        }

        foreach ($fakeBinPaths as $dir) {
            delete_process_logs_fake_bin($dir);
        }
    });

    it('rejects a missing operation token before reading stdin', function (): void {
        [$exitCode, $output] = run_internal_process_logs_command(['--json' => true]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('reads docker logs through fixed argv', function (): void {
        $bin = install_process_logs_fake_bin();

        [$exitCode, $output] = run_internal_process_logs_command(
            [
                '--operation-token' => process_logs_signed_operation_token(),
                '--json' => true,
            ],
            json_encode([
                'backend' => 'docker',
                'runtime_unit' => 'orbit_docs_main_queue',
                'lines' => 25,
                'follow' => false,
            ], JSON_THROW_ON_ERROR),
        );

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data']['output'])
            ->toBe("Vite ready\n")
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('logs --tail 25 orbit_docs_main_queue');
    });

    it('streams followed docker logs as raw output through fixed argv', function (): void {
        $bin = install_process_logs_fake_bin();

        [$exitCode, $output] = run_internal_process_logs_command(
            [
                '--operation-token' => process_logs_signed_operation_token(),
                '--json' => true,
            ],
            json_encode([
                'backend' => 'docker',
                'runtime_unit' => 'orbit_docs_main_queue',
                'lines' => 25,
                'follow' => true,
            ], JSON_THROW_ON_ERROR),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toBe("Vite ready\n")
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('logs --tail 25 --follow orbit_docs_main_queue');
    });
});

function process_logs_signed_operation_token(
    string $id = 'process-logs',
    string $node = 'app-dev',
    string $command = 'internal:process-logs',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: process_logs_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function process_logs_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function run_internal_process_logs_command(array $parameters = [], string $stdin = ''): array
{
    $stream = fopen(filename: 'php://temp', mode: 'r+');
    fwrite($stream, $stdin);
    rewind($stream);

    $input = new ArrayInput($parameters);
    $input->setStream($stream);

    $output = new BufferedOutput;
    $command = Artisan::all()['internal:process-logs'];
    $exitCode = $command->run($input, $output);

    return [$exitCode, $output->fetch()];
}

function install_process_logs_fake_bin(): string
{
    $dir = sys_get_temp_dir().'/orbit-process-logs-bin-'.bin2hex(random_bytes(8));
    mkdir($dir);

    file_put_contents("{$dir}/docker", <<<'PHP'
        #!/usr/bin/env php
        <?php
        file_put_contents(__DIR__.'/calls.log', implode(' ', array_slice($argv, 1)).PHP_EOL, FILE_APPEND);
        echo "Vite ready\n";
        exit(0);
        PHP);
    chmod(filename: "{$dir}/docker", permissions: 0o755);

    $path = getenv('PATH');
    putenv("PATH={$dir}:".($path === false ? '' : $path));

    return $dir;
}

function delete_process_logs_fake_bin(string $path): void
{
    delete_process_logs_file("{$path}/docker");
    delete_process_logs_file("{$path}/calls.log");

    if (is_dir($path)) {
        rmdir($path);
    }
}

function delete_process_logs_file(string $path): void
{
    if (! is_file($path)) {
        return;
    }

    unlink($path);
}

<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal app source create command', function (): void {
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

        $fakeBinPaths = glob(sys_get_temp_dir().'/orbit-app-source-bin-*');

        if ($fakeBinPaths === false) {
            return;
        }

        foreach ($fakeBinPaths as $dir) {
            delete_app_source_fake_bin($dir);
        }
    });

    it('rejects a missing operation token before creating source', function (): void {
        [$exitCode, $output] = run_internal_app_source_create_command([
            'user' => 'orbit',
            'path' => '/home/orbit/apps/docs',
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

    it('creates an empty app source directory with fixed sudo argv', function (): void {
        $bin = install_app_source_fake_bin();

        [$exitCode, $output] = run_internal_app_source_create_command([
            'user' => 'orbit',
            'path' => '/home/orbit/apps/docs',
            '--operation-token' => app_source_create_signed_operation_token(),
            '--json' => true,
        ]);

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($payload['success']['data']['path'])
            ->toBe('/home/orbit/apps/docs')
            ->and($payload['success']['data']['commands'])
            ->toHaveCount(2)
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('install -d -m 755 -o orbit -g orbit /home/orbit/apps')
            ->toContain('install -d -m 755 -o orbit -g orbit /home/orbit/apps/docs');
    });
});

function app_source_create_signed_operation_token(
    string $id = 'app-source-create',
    string $node = 'app-dev',
    string $command = 'internal:app-source:create',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: app_source_create_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function app_source_create_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

/**
 * @param  array<string, mixed>  $parameters
 * @return array{int, string}
 */
function run_internal_app_source_create_command(array $parameters): array
{
    $output = new BufferedOutput;
    $command = Artisan::all()['internal:app-source:create'];
    $exitCode = $command->run(new ArrayInput($parameters), $output);

    return [$exitCode, trim($output->fetch())];
}

function install_app_source_fake_bin(): string
{
    $dir = sys_get_temp_dir().'/orbit-app-source-bin-'.bin2hex(random_bytes(8));
    mkdir($dir);

    file_put_contents("{$dir}/sudo", <<<'PHP'
        #!/usr/bin/env php
        <?php
        file_put_contents(__DIR__.'/calls.log', implode(' ', array_slice($argv, 1)).PHP_EOL, FILE_APPEND);
        exit(0);
        PHP);
    chmod(filename: "{$dir}/sudo", permissions: 0o755);

    $path = getenv('PATH');
    putenv("PATH={$dir}:".($path === false ? '' : $path));

    return $dir;
}

function delete_app_source_fake_bin(string $path): void
{
    delete_app_source_file("{$path}/sudo");
    delete_app_source_file("{$path}/calls.log");

    if (is_dir($path)) {
        rmdir($path);
    }
}

function delete_app_source_file(string $path): void
{
    if (! is_file($path)) {
        return;
    }

    unlink($path);
}

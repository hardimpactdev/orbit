<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;

describe('internal app cache clear command', function (): void {
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

        $fakeBinPaths = glob(sys_get_temp_dir().'/orbit-app-cache-clear-bin-*');

        if ($fakeBinPaths === false) {
            return;
        }

        foreach ($fakeBinPaths as $dir) {
            delete_app_cache_clear_fake_bin($dir);
        }
    });

    it('rejects a missing operation token', function (): void {
        Artisan::call('internal:app-cache:clear', [
            'path' => '/srv/app',
            'php-version' => '8.5',
            'runtime-user' => 'app',
            '--json' => true,
        ]);

        expect(Artisan::output())
            ->json()
            ->toBe(JsonEnvelope::failure(
                'missing_token',
                'Operation token is required.',
            ));
    });

    it('rejects invalid paths before running commands', function (): void {
        Artisan::call('internal:app-cache:clear', [
            'path' => '../app',
            'php-version' => '8.5',
            'runtime-user' => 'app',
            '--operation-token' => app_cache_clear_signed_operation_token(),
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['error']['code'] ?? null)
            ->toBe('validation_failed')
            ->and($payload['error']['message'] ?? null)
            ->toBe('App path must be an absolute path.');
    });

    it('runs artisan and bootstrap cache deletion as the runtime user through fixed argv', function (): void {
        $bin = install_app_cache_clear_fake_bin();
        $appPath = sys_get_temp_dir().'/orbit-app-cache-clear-app-'.bin2hex(random_bytes(8));
        mkdir($appPath.'/bootstrap/cache', recursive: true);
        file_put_contents($appPath.'/artisan', data: '<?php');
        file_put_contents($appPath.'/bootstrap/cache/config.php', data: '<?php return [];');
        file_put_contents($appPath.'/bootstrap/cache/.gitignore', data: '*');

        try {
            $exitCode = Artisan::call('internal:app-cache:clear', [
                'path' => $appPath,
                'php-version' => '8.5',
                'runtime-user' => 'app',
                '--operation-token' => app_cache_clear_signed_operation_token(),
                '--json' => true,
            ]);

            $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

            $calls = file_get_contents("{$bin}/calls.log");

            expect($exitCode)
                ->toBe(0)
                ->and($payload['success']['data']['deleted_cache_files'] ?? null)
                ->toBe(1)
                ->and(is_file($appPath.'/bootstrap/cache/config.php'))
                ->toBeFalse()
                ->and(is_file($appPath.'/bootstrap/cache/.gitignore'))
                ->toBeTrue()
                ->and($calls)
                ->toContain('-u app -H /opt/orbit/php/8.5/bin/php artisan config:clear --no-interaction')
                ->toContain('-u app -H /opt/orbit/php/8.5/bin/php -r')
                ->toContain($appPath.'/bootstrap/cache');
        } finally {
            delete_app_cache_clear_file($appPath.'/bootstrap/cache/config.php');
            delete_app_cache_clear_file($appPath.'/bootstrap/cache/.gitignore');
            delete_app_cache_clear_file($appPath.'/artisan');

            if (is_dir($appPath.'/bootstrap/cache')) {
                rmdir($appPath.'/bootstrap/cache');
            }

            if (is_dir($appPath.'/bootstrap')) {
                rmdir($appPath.'/bootstrap');
            }

            if (is_dir($appPath)) {
                rmdir($appPath);
            }
        }
    });
});

function app_cache_clear_signed_operation_token(
    string $id = 'app-cache-clear',
    string $node = 'app-dev',
    string $command = 'internal:app-cache:clear',
    ?int $issuedAt = null,
    ?int $expiresAt = null,
): string {
    $issuedAt ??= time() - 10;
    $expiresAt ??= time() + 120;

    return new OperationTokenSigner()
        ->sign(
            secret: app_cache_clear_operation_secret(),
            id: $id,
            node: $node,
            command: $command,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
        )
        ->toString();
}

function app_cache_clear_operation_secret(): string
{
    return implode('-', ['gateway', 'secret']);
}

function install_app_cache_clear_fake_bin(): string
{
    $dir = sys_get_temp_dir().'/orbit-app-cache-clear-bin-'.bin2hex(random_bytes(8));
    mkdir($dir);

    file_put_contents("{$dir}/sudo", <<<'PHP'
        #!/usr/bin/env php
        <?php
        file_put_contents(__DIR__.'/calls.log', implode(' ', array_slice($argv, 1)).PHP_EOL, FILE_APPEND);

        if (in_array('-r', $argv, true)) {
            $cachePath = $argv[array_key_last($argv)];
            $deleted = 0;
            $files = glob($cachePath.'/*') ?: [];

            foreach ($files as $file) {
                if (! is_file($file) || basename($file) === '.gitignore') {
                    continue;
                }

                if (unlink($file)) {
                    $deleted++;
                }
            }

            echo $deleted;
        }

        exit(0);
        PHP);
    chmod(filename: "{$dir}/sudo", permissions: 0o755);

    $path = getenv('PATH');
    putenv("PATH={$dir}:".($path === false ? '' : $path));

    return $dir;
}

function delete_app_cache_clear_fake_bin(string $path): void
{
    delete_app_cache_clear_file("{$path}/sudo");
    delete_app_cache_clear_file("{$path}/calls.log");

    if (is_dir($path)) {
        rmdir($path);
    }
}

function delete_app_cache_clear_file(string $path): void
{
    if (! is_file($path)) {
        return;
    }

    unlink($path);
}

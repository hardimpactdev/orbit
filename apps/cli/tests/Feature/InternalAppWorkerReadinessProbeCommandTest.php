<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

describe('internal app worker readiness probe command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
    });

    afterEach(function (): void {
        foreach (glob(sys_get_temp_dir().'/orbit-worker-readiness-*') ?: [] as $dir) {
            delete_worker_readiness_fixture($dir);
        }
    });

    it('rejects a missing operation token before probing the path', function (): void {
        [$exitCode, $output] = run_internal_app_worker_readiness_probe_command([
            'path' => '/home/orbit/apps/docs',
            'workerFile' => 'public/frankenphp-worker.php',
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

    it('rejects relative app paths after token validation', function (): void {
        [$exitCode, $output] = run_internal_app_worker_readiness_probe_command([
            'path' => 'apps/docs',
            'workerFile' => 'public/frankenphp-worker.php',
            '--operation-token' => app_worker_readiness_probe_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'App source path must be an absolute path.',
                ['field' => 'path'],
            ));
    });

    it('rejects unsafe worker file paths after token validation', function (): void {
        [$exitCode, $output] = run_internal_app_worker_readiness_probe_command([
            'path' => '/home/orbit/apps/docs',
            'workerFile' => '../frankenphp-worker.php',
            '--operation-token' => app_worker_readiness_probe_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::failure(
                'validation_failed',
                'Worker file must be a safe relative path.',
                ['field' => 'workerFile'],
            ));
    });

    it('does not report readiness for a bare composer declaration', function (): void {
        $fixture = worker_readiness_fixture_root();
        build_worker_readiness_fixture($fixture, [
            'composer.json' => json_encode([
                'require' => ['laravel/octane' => '^2.0'],
            ], JSON_THROW_ON_ERROR),
        ]);

        [$exitCode, $output] = run_internal_app_worker_readiness_probe_command([
            'path' => $fixture,
            'workerFile' => 'public/frankenphp-worker.php',
            '--operation-token' => app_worker_readiness_probe_signed_operation_token(),
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
            ->toBe(JsonEnvelope::success([
                'path' => $fixture,
                'worker_file' => 'public/frankenphp-worker.php',
                'tokens' => [],
                'stdout' => '',
            ]));
    });

    it('ignores commented frankenphp references and reports installed/runtime file tokens', function (): void {
        $fixture = worker_readiness_fixture_root();
        build_worker_readiness_fixture($fixture, [
            'vendor/laravel/octane/composer.json' => '{}',
            'public/frankenphp-worker.php' => '<?php',
            'config/octane.php' => <<<'PHP'
                <?php

                return [
                    // Default server: 'frankenphp' is what Laravel ships with, but our app
                    // overrides it below. The example below is commented out.
                    # 'server' => 'frankenphp',
                    /* 'server' => 'frankenphp', */
                    'server' => env('OCTANE_SERVER', 'swoole'),
                ];
                PHP,
        ]);

        [$exitCode, $output] = run_internal_app_worker_readiness_probe_command([
            'path' => $fixture,
            'workerFile' => 'public/frankenphp-worker.php',
            '--operation-token' => app_worker_readiness_probe_signed_operation_token(),
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['tokens'])
            ->toBe(['octane:installed', 'frankenphp-worker-file:present'])
            ->and($decoded['success']['data']['stdout'])
            ->not->toContain('frankenphp:configured');
    });

    it('reports every token when octane is installed and configured for frankenphp', function (): void {
        $fixture = worker_readiness_fixture_root();
        build_worker_readiness_fixture($fixture, [
            'vendor/laravel/octane/composer.json' => '{}',
            'web/frankenphp-worker.php' => '<?php',
            'config/octane.php' => <<<'PHP'
                <?php

                return [
                    'server' => env('OCTANE_SERVER', 'frankenphp'),
                ];
                PHP,
        ]);

        [$exitCode, $output] = run_internal_app_worker_readiness_probe_command([
            'path' => $fixture,
            'workerFile' => 'web/frankenphp-worker.php',
            '--operation-token' => app_worker_readiness_probe_signed_operation_token(),
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['tokens'])
            ->toBe([
                'octane:installed',
                'frankenphp-worker-file:present',
                'frankenphp:configured',
            ])
            ->and($decoded['success']['data']['stdout'])
            ->toBe("octane:installed\nfrankenphp-worker-file:present\nfrankenphp:configured\n");
    });
});

function app_worker_readiness_probe_signed_operation_token(
    string $id = 'app-worker-readiness-probe',
    string $node = 'app-dev',
    string $command = 'internal:app-worker-readiness:probe',
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
function run_internal_app_worker_readiness_probe_command(array $parameters): array
{
    $output = new BufferedOutput;
    $exitCode = Artisan::all()['internal:app-worker-readiness:probe']->run(new ArrayInput($parameters), $output);

    return [$exitCode, trim($output->fetch())];
}

function worker_readiness_fixture_root(): string
{
    $root = sys_get_temp_dir().'/orbit-worker-readiness-'.bin2hex(random_bytes(8));
    mkdir($root, recursive: true);

    return $root;
}

/**
 * @param  array<string, string>  $files
 */
function build_worker_readiness_fixture(string $dir, array $files): void
{
    foreach ($files as $relative => $contents) {
        $path = "{$dir}/{$relative}";
        $parent = dirname($path);

        if (! is_dir($parent)) {
            mkdir($parent, recursive: true);
        }

        file_put_contents($path, $contents);
    }
}

function delete_worker_readiness_fixture(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $entries = scandir($path);

    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $child = "{$path}/{$entry}";

        if (is_dir($child)) {
            delete_worker_readiness_fixture($child);

            continue;
        }

        @unlink($child);
    }

    @rmdir($path);
}

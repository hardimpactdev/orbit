<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

it('serves the gateway API through bundled FrankenPHP and Caddy', function (): void {
    $entrypoint = file_get_contents(repo_path('docker/orbit-gateway/entrypoint.sh'));
    $caddyfile = file_get_contents(repo_path('docker/orbit-gateway/Caddyfile'));

    expect($entrypoint)
        ->toContain('run_gateway()')
        ->toContain('exec frankenphp run --config /etc/caddy/Caddyfile --adapter caddyfile')
        ->toContain('serve)')
        ->and($caddyfile)
        ->toContain('frankenphp {')
        ->toContain('num_threads 4')
        ->toContain('max_threads auto')
        ->toContain(':8080')
        ->toContain('php_server')
        ->toContain('root * /srv/orbit/apps/gateway/public');
});

it('runs the scheduler through the gateway artisan command', function (): void {
    $entrypoint = file_get_contents(repo_path('docker/orbit-gateway/entrypoint.sh'));

    expect($entrypoint)
        ->toContain('scheduler)')
        ->toContain('run_artisan orbit-scheduler "$@"')
        ->not->toContain('PHP_CLI_SERVER_WORKERS')
        ->not->toContain('php "$artisan" serve');
});

it('supports mounted source-dev gateway app roots without a separate orbit-gateway entrypoint', function (): void {
    $entrypoint = file_get_contents(repo_path('docker/orbit-gateway/entrypoint.sh'));

    expect($entrypoint)
        ->toContain('ORBIT_GATEWAY_APP_ROOT:-/srv/orbit/apps/gateway')
        ->toContain('run_artisan "$@"')
        ->not->toContain('ORBIT_SOURCE_PATH')
        ->not->toContain('orbit-gateway');
});

it('creates fresh gateway credential state with owner-only modes', function (): void {
    $state = run_gateway_entrypoint_mode_fixture();

    expect(fileperms($state['config_root']) & 0o777)
        ->toBe(0o700)
        ->and(fileperms($state['certificates']) & 0o777)
        ->toBe(0o700)
        ->and(fileperms($state['operations_websocket']) & 0o777)
        ->toBe(0o700)
        ->and(fileperms($state['environment']) & 0o777)
        ->toBe(0o600)
        ->and(fileperms($state['database']) & 0o777)
        ->toBe(0o600);

    File::deleteDirectory($state['root']);
});

it('repairs pre-existing gateway credential and TLS key modes before startup', function (): void {
    $state = run_gateway_entrypoint_mode_fixture(preExisting: true);

    expect(fileperms($state['environment']) & 0o777)
        ->toBe(0o600)
        ->and(fileperms($state['database']) & 0o777)
        ->toBe(0o600)
        ->and(fileperms($state['tls_key']) & 0o777)
        ->toBe(0o600)
        ->and(fileperms($state['operations_apps']) & 0o777)
        ->toBe(0o600);

    File::deleteDirectory($state['root']);
});

/**
 * @return array{root: string, config_root: string, certificates: string, operations_websocket: string, environment: string, database: string, tls_key: string, operations_apps: string}
 */
function run_gateway_entrypoint_mode_fixture(bool $preExisting = false): array
{
    $root = sys_get_temp_dir().'/orbit-gateway-entrypoint-'.bin2hex(random_bytes(6));
    $appRoot = "{$root}/app";
    $configRoot = "{$root}/config";
    $certificates = "{$configRoot}/certs";
    $operationsWebSocket = "{$configRoot}/operations-websocket";
    $environment = "{$configRoot}/.env";
    $database = "{$configRoot}/gateway.sqlite";
    $tlsKey = "{$certificates}/gateway.key";
    $operationsApps = "{$operationsWebSocket}/apps.php";
    $bin = "{$root}/bin";

    File::ensureDirectoryExists($appRoot);
    File::ensureDirectoryExists($bin);
    File::put("{$appRoot}/.env.example", '');
    File::put("{$bin}/php", "#!/usr/bin/env bash\nexit 0\n");
    File::put("{$bin}/id", "#!/usr/bin/env bash\nexit 1\n");
    File::chmod("{$bin}/php", 0o755);
    File::chmod("{$bin}/id", 0o755);

    if ($preExisting) {
        File::ensureDirectoryExists($certificates);
        File::ensureDirectoryExists($operationsWebSocket);
        File::put($environment, 'EXISTING=1'.PHP_EOL);
        File::put($database, 'database');
        File::put($tlsKey, 'private key');
        File::put($operationsApps, '<?php return [];');

        foreach ([$configRoot, $certificates, $operationsWebSocket] as $directory) {
            File::chmod($directory, 0o755);
        }

        foreach ([$environment, $database, $tlsKey, $operationsApps] as $file) {
            File::chmod($file, 0o644);
        }
    }

    $path = getenv('PATH');
    $process = new Process(
        ['/bin/bash', repo_path('docker/orbit-gateway/entrypoint.sh'), 'artisan', 'about'],
        repo_path(),
        [
            'ORBIT_GATEWAY_APP_ROOT' => $appRoot,
            'ORBIT_CONFIG_ROOT' => $configRoot,
            'PATH' => "{$bin}:".($path === false ? '' : $path),
        ],
    );
    $process->mustRun();

    return [
        'root' => $root,
        'config_root' => $configRoot,
        'certificates' => $certificates,
        'operations_websocket' => $operationsWebSocket,
        'environment' => $environment,
        'database' => $database,
        'tls_key' => $tlsKey,
        'operations_apps' => $operationsApps,
    ];
}

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

it('does not hardcode image orbit ownership for bind-mounted gateway config roots', function (): void {
    $entrypoint = file_get_contents(repo_path('docker/orbit-gateway/entrypoint.sh'));

    expect($entrypoint)
        ->toContain('ORBIT_HOST_PATH_PREFIX')
        ->toContain('resolve_config_root_owner')
        ->toContain("stat -c '%u:%g'")
        ->not->toContain('chown -R orbit:orbit "$config_root"')
        ->not->toContain('chown -R 1001:1001')
        ->not->toContain('caddy:systemd-journal');
});

it('owns a host-prefix config root with host home uid:gid instead of the image orbit user', function (): void {
    $state = run_gateway_entrypoint_ownership_fixture(withHostPrefix: true);

    $hostOwner = sprintf('%d:%d', $state['host_uid'], $state['host_gid']);
    $imageOwner = '999:999';
    $chownLog = trim((string) file_get_contents($state['chown_log']));

    expect($state['process']->isSuccessful())
        ->toBeTrue()
        ->and($chownLog)
        ->toContain("-R {$hostOwner} {$state['config_root']}")
        ->and($chownLog)
        ->not->toContain("-R {$imageOwner} ")->and($chownLog)
        ->not->toContain('orbit:orbit');

    File::deleteDirectory($state['root']);
});

it('keeps host config-root ownership stable across entrypoint restart simulations', function (): void {
    $state = run_gateway_entrypoint_ownership_fixture(withHostPrefix: true, invokeCount: 2);

    $hostOwner = sprintf('%d:%d', $state['host_uid'], $state['host_gid']);
    $chownLines = array_values(array_filter(
        explode("\n", trim((string) file_get_contents($state['chown_log']))),
        static fn (string $line): bool => $line !== '',
    ));

    expect($state['process']->isSuccessful())
        ->toBeTrue()
        ->and($chownLines)
        ->toHaveCount(2)
        ->and($chownLines[0])
        ->toBe("-R {$hostOwner} {$state['config_root']}")
        ->and($chownLines[1])
        ->toBe("-R {$hostOwner} {$state['config_root']}");

    File::deleteDirectory($state['root']);
});

it('falls back to the image orbit numeric identity when no host path prefix is configured', function (): void {
    $state = run_gateway_entrypoint_ownership_fixture(withHostPrefix: false);

    $chownLog = trim((string) file_get_contents($state['chown_log']));

    expect($state['process']->isSuccessful())
        ->toBeTrue()
        ->and($chownLog)
        ->toContain("-R 999:999 {$state['config_root']}")
        ->and($chownLog)
        ->not->toContain('orbit:orbit');

    File::deleteDirectory($state['root']);
});

it('fails safely when a host path prefix is set but host ownership cannot be resolved', function (): void {
    $state = run_gateway_entrypoint_ownership_fixture(
        withHostPrefix: true,
        createHostHome: false,
        expectSuccess: false,
    );

    $output = $state['process']->getErrorOutput().$state['process']->getOutput();
    $chownLog = is_file($state['chown_log'])
        ? trim((string) file_get_contents($state['chown_log']))
        : '';

    expect($state['process']->isSuccessful())
        ->toBeFalse()
        ->and($output)
        ->toContain('Unable to resolve host ownership for gateway config root')
        ->and($chownLog)
        ->toBe('');

    File::deleteDirectory($state['root']);
});

it('preserves literal backslashes and ampersands when rewriting existing gateway env values', function (): void {
    $root = sys_get_temp_dir().'/orbit-gateway-entrypoint-env-'.bin2hex(random_bytes(6));
    $appRoot = "{$root}/app";
    $configRoot = "{$root}/config";
    $environment = "{$configRoot}/.env";
    $bin = "{$root}/bin";
    $literalValue = 'prod\\slice&amp;keep\\n-not-escape';

    File::ensureDirectoryExists($appRoot);
    File::ensureDirectoryExists($configRoot);
    File::ensureDirectoryExists($bin);
    File::put("{$appRoot}/.env.example", '');
    File::put($environment, "APP_ENV=old\n");
    File::put("{$bin}/php", "#!/usr/bin/env bash\nexit 0\n");
    File::put("{$bin}/id", "#!/usr/bin/env bash\nexit 1\n");
    File::chmod("{$bin}/php", 0o755);
    File::chmod("{$bin}/id", 0o755);

    $path = getenv('PATH');
    $process = new Process(
        ['/bin/bash', repo_path('docker/orbit-gateway/entrypoint.sh'), 'artisan', 'about'],
        repo_path(),
        [
            'ORBIT_GATEWAY_APP_ROOT' => $appRoot,
            'ORBIT_CONFIG_ROOT' => $configRoot,
            'APP_ENV' => $literalValue,
            'PATH' => "{$bin}:".($path === false ? '' : $path),
        ],
    );
    $process->mustRun();

    $envContents = (string) file_get_contents($environment);

    expect($envContents)
        ->toContain("APP_ENV={$literalValue}")
        ->not
        ->toContain('APP_ENV=old')
        ->and(str_contains($envContents, 'prod\\slice&amp;keep\\n-not-escape'))
        ->toBeTrue();

    File::deleteDirectory($root);
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

/**
 * @return array{
 *     root: string,
 *     config_root: string,
 *     chown_log: string,
 *     host_uid: int,
 *     host_gid: int,
 *     process: Process
 * }
 */
function run_gateway_entrypoint_ownership_fixture(
    bool $withHostPrefix,
    bool $createHostHome = true,
    bool $expectSuccess = true,
    int $invokeCount = 1,
): array {
    $root = sys_get_temp_dir().'/orbit-gateway-entrypoint-owner-'.bin2hex(random_bytes(6));
    $appRoot = "{$root}/app";
    $homeDir = "{$root}/home/orbit";
    $configRoot = "{$homeDir}/.config/orbit";
    $hostPrefix = "{$root}/mnt/orbit-host";
    $hostHome = $hostPrefix.$homeDir;
    $bin = "{$root}/bin";
    $chownLog = "{$root}/chown.log";

    File::ensureDirectoryExists($appRoot);
    File::ensureDirectoryExists($bin);
    File::ensureDirectoryExists($configRoot);
    File::put("{$appRoot}/.env.example", '');
    File::put("{$bin}/php", "#!/usr/bin/env bash\nexit 0\n");
    File::put(
        "{$bin}/id",
        <<<'BASH'
            #!/usr/bin/env bash
            set -euo pipefail

            if [ "${1:-}" = "orbit" ]; then
                exit 0
            fi

            if [ "${1:-}" = "-u" ] && [ "${2:-}" = "orbit" ]; then
                printf '999\n'
                exit 0
            fi

            if [ "${1:-}" = "-g" ] && [ "${2:-}" = "orbit" ]; then
                printf '999\n'
                exit 0
            fi

            exit 1
            BASH,
    );
    File::put(
        "{$bin}/chown",
        <<<BASH
            #!/usr/bin/env bash
            set -euo pipefail
            printf '%s\n' "\$*" >> "{$chownLog}"
            exit 0
            BASH,
    );
    File::chmod("{$bin}/php", 0o755);
    File::chmod("{$bin}/id", 0o755);
    File::chmod("{$bin}/chown", 0o755);

    if ($createHostHome) {
        File::ensureDirectoryExists($hostHome);
    }

    $path = getenv('PATH');
    $environment = [
        'ORBIT_GATEWAY_APP_ROOT' => $appRoot,
        'ORBIT_CONFIG_ROOT' => $configRoot,
        'PATH' => "{$bin}:".($path === false ? '' : $path),
        'CHOWN_LOG' => $chownLog,
    ];

    if ($withHostPrefix) {
        $environment['ORBIT_HOST_PATH_PREFIX'] = $hostPrefix;
    }

    // Run the real entrypoint path(s) through bash so restart-style reentry is exercised.
    $script = implode(' && ', array_map(
        static fn (): string => (
            escapeshellarg('/bin/bash')
            .' '
            .escapeshellarg(repo_path('docker/orbit-gateway/entrypoint.sh'))
            .' artisan about'
        ),
        range(1, max(1, $invokeCount)),
    ));

    $process = new Process(
        ['/bin/bash', '-c', $script],
        repo_path(),
        $environment,
    );
    $process->run();

    if ($expectSuccess && ! $process->isSuccessful()) {
        throw new RuntimeException(
            "Gateway entrypoint ownership fixture failed:\n".$process->getErrorOutput().$process->getOutput(),
        );
    }

    $identityPath = $createHostHome ? $hostHome : $homeDir;

    return [
        'root' => $root,
        'config_root' => $configRoot,
        'chown_log' => $chownLog,
        'host_uid' => (int) fileowner($identityPath),
        'host_gid' => (int) filegroup($identityPath),
        'process' => $process,
    ];
}

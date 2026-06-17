<?php

declare(strict_types=1);

use App\E2E\Support\OrbitCliBinaryBundle;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

/**
 * Binary self-test lane (4a — build + binary self-test, no topology).
 *
 * Asserts packaging-specific behavior of the host-arch native binary built by
 * phpacker. The binary is resolved from the env var ORBIT_E2E_BINARY_PATH (set by
 * the composer test:e2e:binary script) or falls back to the conventional mac-arm
 * path relative to the repo root.
 *
 * Self-test coverage:
 *   - version   : --version prints the expected version from VERSION.
 *   - list      : `list` exits 0 and shows command groups (proves phar:// autoload +
 *                 NativeCommandNormalizer resolve phar-relative).
 *   - extensions: a gateway command --help loads without missing-extension errors
 *                 (openssl/curl-dependent service providers boot without crash).
 *   - pdo_sqlite: `internal:database-query-local` executes `SELECT 1` on a temp
 *                 SQLite file and returns the expected row (proves pdo_sqlite is
 *                 compiled into the embedded runtime).
 *   - proc_open : `dns:resolve-tld` calls `which dnsmasq` via Symfony\Process before
 *                 any system write; a valid JSON response (not a PHP fatal) proves
 *                 proc_open is enabled and unrestricted in the embedded runtime.
 *   - LocalResolver: `dns:list --json` with a sandboxed HOME that has a pre-created
 *                 dnsmasq.d config file asserts the resolver reads from
 *                 $HOME/.config/orbit/dnsmasq.d and not from phar:// storage_path().
 */
pest()->group('e2e-binary');

function requireOrbitBinaryBuilt(): void
{
    if (! file_exists(orbitBinaryPath())) {
        test()->markTestSkipped('orbit binary not built; run `composer test:e2e:binary`.');
    }
}

/**
 * Resolve the path to the built host-arch native binary.
 *
 * Reads ORBIT_E2E_BINARY_PATH (set by the composer script) or falls back to the
 * conventional mac-arm path under apps/cli/builds/dist/.
 */
function orbitBinaryPath(): string
{
    $fromEnv = getenv('ORBIT_E2E_BINARY_PATH');

    if (is_string($fromEnv) && $fromEnv !== '') {
        return $fromEnv;
    }

    return repo_path('apps/cli/builds/dist/mac/mac-arm');
}

/**
 * Run the built orbit binary with the given arguments and environment.
 *
 * Returns an array with exit_code, stdout, and stderr keys. The binary path must
 * exist; the test that calls this function is responsible for asserting that.
 *
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env  Extra env vars merged on top of the inherited environment.
 * @param  string|null  $stdin  Optional stdin content piped into the process.
 * @return array{exit_code: int, stdout: string, stderr: string}
 */
function runOrbitBinary(array $arguments, array $env = [], ?string $stdin = null): array
{
    $binary = orbitBinaryPath();
    $command = array_merge([$binary], $arguments);

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $environment = array_filter(getenv(), is_string(...));

    $process = proc_open(
        $command,
        $descriptors,
        $pipes,
        null,
        [...$environment, ...$env],
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Failed to start orbit binary process: '.$binary);
    }

    if ($stdin !== null) {
        fwrite($pipes[0], $stdin);
    }

    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exit_code' => proc_close($process),
        'stdout' => $stdout === false ? '' : $stdout,
        'stderr' => $stderr === false ? '' : $stderr,
    ];
}

/**
 * Create a temporary sandbox HOME directory for binary invocations that read HOME.
 *
 * Returns the path. Callers are responsible for cleanup via remove_directory().
 */
function makeSandboxHome(): string
{
    return make_temp_directory('binary-home');
}

/**
 * @template TReturn
 *
 * @param  callable(string): TReturn  $callback
 * @return TReturn
 */
function withBinaryFakeGatewayVerificationServer(callable $callback): mixed
{
    $directory = sys_get_temp_dir().'/orbit-e2e-binary-gateway-'.bin2hex(random_bytes(8));
    mkdir($directory, recursive: true);

    $routerPath = "{$directory}/router.php";
    file_put_contents($routerPath, <<<'PHP'
<?php

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$payload = json_decode(file_get_contents('php://input') ?: '{}', true);

http_response_code(200);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || $path !== '/api/internal-executor/token/verify'
    || ! is_array($payload)
    || ($payload['command'] ?? null) !== 'internal:database-query-local'
) {
    http_response_code(404);

    echo json_encode([
        'error' => [
            'code' => 'unexpected_gateway_request',
            'message' => 'Unexpected gateway verification request.',
            'details' => [
                'method' => $_SERVER['REQUEST_METHOD'] ?? null,
                'path' => $path,
                'payload' => $payload,
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    return;
}

echo json_encode([
    'success' => [
        'data' => [
            'allowed' => true,
        ],
        'meta' => [],
    ],
], JSON_THROW_ON_ERROR);
PHP);

    $server = null;

    try {
        [$server, $gatewayUrl] = startBinaryFakeGatewayVerificationServer($routerPath, $directory);

        return $callback($gatewayUrl);
    } finally {
        if ($server instanceof SymfonyProcess) {
            $server->stop(0);
        }

        if (is_file($routerPath)) {
            unlink($routerPath);
        }

        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}

/**
 * @return array{SymfonyProcess, string}
 */
function startBinaryFakeGatewayVerificationServer(string $routerPath, string $directory): array
{
    $lastError = null;

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $port = reserveBinaryFakeGatewayLocalPort();
        $gatewayUrl = "http://127.0.0.1:{$port}";
        $server = new SymfonyProcess([PHP_BINARY, '-S', "127.0.0.1:{$port}", $routerPath], $directory);
        $server->start();

        try {
            waitForBinaryFakeGatewayServer($server, $gatewayUrl);

            return [$server, $gatewayUrl];
        } catch (RuntimeException $exception) {
            $lastError = $exception;
            $server->stop(0);
            usleep(10_000);
        }
    }

    throw new RuntimeException(
        'Unable to start fake gateway verification server after retrying available localhost ports.',
        previous: $lastError,
    );
}

function reserveBinaryFakeGatewayLocalPort(): int
{
    $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errorMessage);

    if ($socket === false) {
        throw new RuntimeException("Unable to reserve a local port: {$errorMessage} ({$errno}).");
    }

    $address = stream_socket_get_name($socket, false);
    fclose($socket);

    if (! is_string($address) || preg_match('/:(\d+)$/', $address, $matches) !== 1) {
        throw new RuntimeException('Unable to determine the reserved local port.');
    }

    return (int) $matches[1];
}

function waitForBinaryFakeGatewayServer(SymfonyProcess $server, string $gatewayUrl): void
{
    $timeoutAt = microtime(true) + 5;
    $context = stream_context_create([
        'http' => [
            'ignore_errors' => true,
            'timeout' => 0.2,
        ],
    ]);

    while (microtime(true) < $timeoutAt) {
        if (! $server->isRunning()) {
            throw new RuntimeException(
                "Fake gateway verification server exited before becoming reachable.\n\n".binaryFakeGatewayServerOutput($server),
            );
        }

        $response = @file_get_contents($gatewayUrl, false, $context);

        if ($response !== false) {
            return;
        }

        usleep(10_000);
    }

    throw new RuntimeException(
        "Timed out waiting for fake gateway verification server at {$gatewayUrl}.\n\n".binaryFakeGatewayServerOutput($server),
    );
}

function binaryFakeGatewayServerOutput(SymfonyProcess $server): string
{
    return trim($server->getOutput().$server->getErrorOutput());
}

// ─── Self-tests ────────────────────────────────────────────────────────────────

it('binary exists and is executable', function (): void {
    requireOrbitBinaryBuilt();

    $path = orbitBinaryPath();

    expect($path)->toBeFile()
        ->and(is_executable($path))->toBeTrue('Binary at '.$path.' is not executable');
});

it('keeps the native binary artifact path separate from the source CLI entrypoint', function (): void {
    $bundleDir = make_temp_directory('binary-bundle-contract');

    try {
        expect(OrbitCliBinaryBundle::bundledBinaryPath($bundleDir))
            ->toContain('orbit-binary')
            ->not->toContain('apps/cli/orbit')
            ->and(orbitBinaryPath())
            ->not->toContain('apps/cli/orbit');
    } finally {
        remove_directory($bundleDir);
    }
});

it('stages the linux binary into the bundled binary path', function (): void {
    $bundleDir = make_temp_directory('binary-bundle-stage');
    $linuxDistDir = repo_path('apps/cli/builds/dist/linux');
    $binarySource = "{$linuxDistDir}/linux-x64";
    $fakeBinaryContents = 'fake-linux-binary-'.bin2hex(random_bytes(8));
    $createdBinarySource = false;

    Process::fake(function ($process) use ($binarySource, $fakeBinaryContents, $linuxDistDir, &$createdBinarySource) {
        if ($process->command === 'bin/orbit-build-cli-binary linux x64 "$(bin/orbit-version)"') {
            if (! is_file($binarySource)) {
                if (! is_dir($linuxDistDir)) {
                    mkdir($linuxDistDir, 0777, recursive: true);
                }

                file_put_contents($binarySource, $fakeBinaryContents);
                chmod($binarySource, 0755);
                $createdBinarySource = true;
            }

            return Process::result();
        }

        if (is_string($process->command) && str_starts_with($process->command, 'file ')) {
            return Process::result(output: 'ELF 64-bit LSB executable, x86-64');
        }

        return Process::result();
    });
    Process::preventStrayProcesses();

    try {
        (new OrbitCliBinaryBundle)->buildLinuxBinaryInto($bundleDir);

        $bundleBinary = OrbitCliBinaryBundle::bundledBinaryPath($bundleDir);
        $sourceHash = hash_file('sha256', $binarySource);

        expect($bundleBinary)
            ->toBeFile()
            ->not->toContain('apps/cli/orbit')
            ->and(hash_file('sha256', $bundleBinary))->toBe($sourceHash)
            ->and(substr(sprintf('%o', fileperms($bundleBinary)), -3))->toBe('755');

        Process::assertRan(fn ($process): bool => $process->path === repo_path()
            && $process->command === 'bin/orbit-build-cli-binary linux x64 "$(bin/orbit-version)"');
    } finally {
        remove_directory($bundleDir);

        if ($createdBinarySource) {
            @unlink($binarySource);
            @rmdir($linuxDistDir);
            @rmdir(dirname($linuxDistDir));
        }
    }
});

it('reuses a cached linux binary for matching fingerprints', function (): void {
    $bundleDir = make_temp_directory('binary-bundle-cache');
    $cacheRoot = make_temp_directory('binary-cache-root');
    $previousXdgCacheHome = getenv('XDG_CACHE_HOME');
    $fingerprint = str_repeat('a', 64);
    $cacheBinary = "{$cacheRoot}/orbit-e2e/cli-binaries/{$fingerprint}/orbit-binary";

    mkdir(dirname($cacheBinary), 0755, recursive: true);
    file_put_contents($cacheBinary, 'cached-linux-binary');
    chmod($cacheBinary, 0755);
    putenv("XDG_CACHE_HOME={$cacheRoot}");

    Process::fake();
    Process::preventStrayProcesses();

    try {
        (new OrbitCliBinaryBundle)->buildLinuxBinaryInto($bundleDir, $fingerprint);

        $bundleBinary = OrbitCliBinaryBundle::bundledBinaryPath($bundleDir);

        expect($bundleBinary)
            ->toBeFile()
            ->and(file_get_contents($bundleBinary))->toBe('cached-linux-binary')
            ->and(substr(sprintf('%o', fileperms($bundleBinary)), -3))->toBe('755');

        Process::assertNothingRan();
    } finally {
        $previousXdgCacheHome === false ? putenv('XDG_CACHE_HOME') : putenv("XDG_CACHE_HOME={$previousXdgCacheHome}");
        remove_directory($bundleDir);
        remove_directory($cacheRoot);
    }
});

it('--version prints the expected version', function (): void {
    requireOrbitBinaryBuilt();

    $version = trim((string) file_get_contents(repo_path('VERSION')));

    expect($version)->not->toBeEmpty('Could not parse version from VERSION');

    $sandboxHome = makeSandboxHome();

    try {
        // Use --no-ansi to strip ANSI color codes from the version output.
        $result = runOrbitBinary(['--version', '--no-ansi'], env: ['HOME' => $sandboxHome]);

        // Note: Pest 4's toContain() is variadic — do not pass a failure message as a
        // second argument; it would be interpreted as a second needle and fail.
        expect($result['exit_code'])->toBe(0, 'Binary --version exited with '.$result['exit_code'].': '.$result['stderr']);
        expect($result['stdout'])->toContain($version);
    } finally {
        remove_directory($sandboxHome);
    }
});

it('list exits 0 and shows command groups', function (): void {
    requireOrbitBinaryBuilt();

    $sandboxHome = makeSandboxHome();

    try {
        $result = runOrbitBinary(['list'], env: ['HOME' => $sandboxHome]);

        expect($result['exit_code'])->toBe(0, 'Binary list exited with '.$result['exit_code'].': '.$result['stderr'])
            ->and($result['stdout'])->toContain('dns:')
            ->and($result['stdout'])->toContain('gateway:')
            ->and($result['stdout'])->toContain('node:')
            ->and($result['stdout'])->toContain('app:');
    } finally {
        remove_directory($sandboxHome);
    }
});

it('gateway command --help loads without missing-extension errors', function (): void {
    requireOrbitBinaryBuilt();

    // Proves that openssl/curl-dependent service providers (GatewayApiServiceProvider,
    // OperationTokenGuardServiceProvider) boot without any "extension not found" error.
    // gateway:status depends on curl+openssl; loading its --help exercises the providers.
    $sandboxHome = makeSandboxHome();

    try {
        $result = runOrbitBinary(['gateway:status', '--help'], env: ['HOME' => $sandboxHome]);

        $combined = $result['stdout'].$result['stderr'];

        expect($result['exit_code'])->toBe(0, 'gateway:status --help exited '.$result['exit_code'].': '.$combined)
            ->and($combined)->not->toContain('extension')
            ->and($combined)->not->toContain('Class not found')
            ->and($result['stdout'])->toContain('gateway:status');
    } finally {
        remove_directory($sandboxHome);
    }
});

it('pdo_sqlite: internal:database-query-local executes SELECT 1 on a temp SQLite file', function (): void {
    requireOrbitBinaryBuilt();

    // Proves the embedded runtime has pdo_sqlite compiled in.
    // The command opens a new SQLite file at a temp path and returns a result row.
    // A missing pdo_sqlite driver would produce a PDOException before the token check,
    // but here the token check happens first; a successful query proves pdo_sqlite works.
    $sandboxHome = makeSandboxHome();
    $tmpDb = $sandboxHome.'/test.sqlite';
    $token = 'e2e-binary-self-test-token-'.bin2hex(random_bytes(8));

    try {
        $payload = json_encode([
            'connection' => ['driver' => 'sqlite', 'path' => $tmpDb],
            'sql' => 'SELECT 1 AS val',
        ], JSON_THROW_ON_ERROR);

        $result = withBinaryFakeGatewayVerificationServer(
            fn (string $gatewayUrl): array => runOrbitBinary(
                ['internal:database-query-local', '--operation-token='.$token, '--json'],
                env: [
                    'HOME' => $sandboxHome,
                    'ORBIT_GATEWAY_URL' => $gatewayUrl,
                ],
                stdin: $payload,
            ),
        );

        expect($result['exit_code'])->toBe(0, 'database-query-local exited '.$result['exit_code'].': '.$result['stdout'].$result['stderr']);

        $json = json_decode($result['stdout'], associative: true, flags: JSON_THROW_ON_ERROR);

        expect($json)->toHaveKey('success')
            ->and($json['success']['data']['columns'])->toBe(['val'])
            ->and($json['success']['data']['rows'])->toBe([['val' => 1]]);
    } finally {
        remove_directory($sandboxHome);
    }
});

it('proc_open: dns:resolve-tld produces valid JSON output (proc_open is unrestricted)', function (): void {
    requireOrbitBinaryBuilt();

    // dns:resolve-tld calls LocalResolver::isDnsmasqInstalled() which runs
    // `which dnsmasq` via Symfony\Process (proc_open). If proc_open is disabled or
    // restricted, the binary crashes with a PHP fatal error before emitting any output.
    // Asserting the output is decodable JSON proves proc_open ran successfully.
    //
    // The command may succeed (dnsmasq installed + sudo available) or fail with a
    // known JSON error (dnsmasq absent / platform unsupported). Either is acceptable;
    // a non-JSON or empty output signals proc_open failure.
    $tld = 'orbit-e2e-procopen-'.bin2hex(random_bytes(3));
    $sandboxHome = makeSandboxHome();

    try {
        $result = runOrbitBinary(
            ['dns:resolve-tld', $tld, '127.0.0.1', '--json'],
            env: ['HOME' => $sandboxHome],
        );

        $stdout = trim($result['stdout']);

        expect($stdout)->not->toBeEmpty('Binary produced no output — proc_open may be disabled')
            ->and(json_decode($stdout, true))->not->toBeNull('Binary output is not valid JSON — proc_open may be disabled or produced a fatal error');
    } finally {
        // Clean up /etc/resolver/<tld> if the command wrote it (dnsmasq installed + sudo worked).
        if (file_exists('/etc/resolver/'.$tld)) {
            exec('sudo rm -f '.escapeshellarg('/etc/resolver/'.$tld));
        }

        remove_directory($sandboxHome);
    }
});

it('LocalResolver reads config from $HOME/.config/orbit/dnsmasq.d not from phar:// storage', function (): void {
    requireOrbitBinaryBuilt();

    // Places a dnsmasq.d override file inside a sandboxed HOME, then runs dns:list --json.
    // Asserting the listing returns the override proves LocalResolver::configDir() resolves
    // to $HOME/.config/orbit/dnsmasq.d and not to phar:// (or any other path inside the
    // PHAR). This is the CLI-BINARY-02C runtime coverage asserted in the 4a lane.
    $sandboxHome = makeSandboxHome();
    $tld = 'orbit-e2e-localtest';
    $target = '192.0.2.1';
    $configDir = $sandboxHome.'/.config/orbit/dnsmasq.d';
    $configFile = $configDir.'/'.$tld.'.conf';

    try {
        mkdir($configDir, 0700, recursive: true);
        // dnsmasq config format requires a leading dot before the TLD label.
        file_put_contents($configFile, "address=/.{$tld}/{$target}\n");

        $result = runOrbitBinary(
            ['dns:list', '--json'],
            env: ['HOME' => $sandboxHome],
        );

        expect($result['exit_code'])->toBe(0, 'dns:list --json exited '.$result['exit_code'].': '.$result['stdout'].$result['stderr']);

        $json = json_decode(trim($result['stdout']), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($json)->toHaveKey('success');

        $dns = $json['success']['data']['dns'] ?? [];

        expect($dns)->not->toBeEmpty('dns:list returned no overrides — resolver is not reading from the sandbox HOME')
            ->and($dns[0]['tld'])->toBe($tld)
            ->and($dns[0]['target'])->toBe($target)
            ->and($dns[0]['resolver_backend'])->toBe('dnsmasq');
    } finally {
        remove_directory($sandboxHome);
    }
});

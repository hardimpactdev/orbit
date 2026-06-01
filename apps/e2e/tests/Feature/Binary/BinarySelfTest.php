<?php

declare(strict_types=1);

use App\E2E\Support\OrbitCliBinaryBundle;

/**
 * Binary self-test lane (4a — build + binary self-test, no topology).
 *
 * Asserts packaging-specific behavior of the host-arch native binary built by
 * phpacker. The binary is resolved from the env var ORBIT_E2E_BINARY_PATH (set by
 * the composer test:e2e:binary script) or falls back to the conventional mac-arm
 * path relative to the repo root.
 *
 * Self-test coverage:
 *   - version   : --version prints the expected version from apps/cli/config/app.php.
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
 * Generate a valid OperationToken compact string.
 *
 * Mirrors the signing logic from Orbit\Core\Security\OperationTokenSigner so the
 * e2e harness can produce tokens without importing the orbit-core package.
 *
 * @param  array{secret: string, node: string, command: string, ttl_seconds?: int}  $params
 */
function generateOperationToken(array $params): string
{
    $secret = $params['secret'];
    $node = $params['node'];
    $command = $params['command'];
    $ttl = $params['ttl_seconds'] ?? 300;
    $issuedAt = time();
    $expiresAt = $issuedAt + $ttl;
    $id = bin2hex(random_bytes(8));

    $encode = static fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');
    $lengthPrefixed = static fn (string $v): string => pack('N', strlen($v)).$v;

    $canonicalPayload = $lengthPrefixed($id)
        .$lengthPrefixed($node)
        .$lengthPrefixed($command)
        .$lengthPrefixed((string) $issuedAt)
        .$lengthPrefixed((string) $expiresAt);

    $signature = rtrim(
        strtr(base64_encode(hash_hmac('sha256', $canonicalPayload, $secret, true)), '+/', '-_'),
        '=',
    );

    return implode('.', [
        $encode($id),
        $encode($node),
        $encode($command),
        $encode((string) $issuedAt),
        $encode((string) $expiresAt),
        $signature,
    ]);
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

it('--version prints the expected version', function (): void {
    requireOrbitBinaryBuilt();

    // Parse the version from apps/cli/config/app.php without executing it (env() is not
    // available outside the Laravel boot context, so require would throw).
    $configSource = file_get_contents(repo_path('apps/cli/config/app.php')) ?: '';
    preg_match("/'version'\s*=>\s*'([^']+)'/", $configSource, $matches);
    $version = $matches[1] ?? '';

    expect($version)->not->toBeEmpty('Could not parse version from apps/cli/config/app.php');

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
    $secret = 'e2e-binary-self-test-secret-'.bin2hex(random_bytes(4));
    $node = 'e2e-binary-test-node';
    $command = 'internal:database-query-local';

    try {
        $token = generateOperationToken([
            'secret' => $secret,
            'node' => $node,
            'command' => $command,
            'ttl_seconds' => 300,
        ]);

        $payload = json_encode([
            'connection' => ['driver' => 'sqlite', 'path' => $tmpDb],
            'sql' => 'SELECT 1 AS val',
        ], JSON_THROW_ON_ERROR);

        $result = runOrbitBinary(
            ['internal:database-query-local', '--operation-token='.$token, '--json'],
            env: [
                'HOME' => $sandboxHome,
                'ORBIT_EXECUTOR_SECRET' => $secret,
                'ORBIT_NODE_IDENTITY' => $node,
            ],
            stdin: $payload,
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

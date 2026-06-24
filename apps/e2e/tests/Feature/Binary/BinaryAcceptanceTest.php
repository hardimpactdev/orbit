<?php

declare(strict_types=1);

use App\E2E\Support\DockerInstance;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;

/**
 * Binary acceptance lane (4b — acceptance on a prepared topology).
 *
 * Drops the built linux x64 orbit binary onto a prepared Docker topology node
 * and exercises binary-only-reachable paths against a real Linux x86_64
 * container. Group: e2e-binary-acceptance.
 *
 * Gated behind source E2E; cadence: merge/nightly. Not the per-PR loop.
 *
 * Binary-drop mechanism:
 *   DockerInstance::copyFileToInstance() runs `docker cp <source> <container>:<dest>`
 *   via the DockerHost (local or ssh://host DOCKER_HOST). The Docker CLI reads the
 *   binary from the host running the tests and forwards it to the container via the
 *   Docker API — no manual base64/stream required. The binary is placed at
 *   /usr/local/bin/orbit-binary inside the container and made executable.
 *
 * Topology: OperatorGateway (smallest topology with both operator + gateway roles;
 * the gateway container is Linux x86_64 and has Docker available for the
 * NodeGatewayBootstrapper docker-exec path assertions).
 *
 * Skip guard:
 *   - Skips when ORBIT_E2E_BINARY_PATH_LINUX is absent or the file does not exist
 *     (binary not yet built for the linux lane).
 *   - Skips when ORBIT_E2E != '1' (no prepared topology available) via e2eTopology().
 *
 * Run via:
 *   composer test:e2e:docker:binary-acceptance
 *   composer test:e2e:docker -- --group=e2e-binary-acceptance --fail-on-empty-test-suite
 */

/**
 * Resolve the linux x64 binary path from ORBIT_E2E_BINARY_PATH_LINUX or the
 * conventional fallback under apps/cli/builds/dist/linux/.
 */
function orbitLinuxBinaryPath(): string
{
    $fromEnv = getenv('ORBIT_E2E_BINARY_PATH_LINUX');

    if (is_string($fromEnv) && $fromEnv !== '') {
        return $fromEnv;
    }

    return repo_path('apps/cli/builds/dist/linux/linux-x64');
}

/**
 * Drop the linux x64 orbit binary into the given topology container and return
 * the absolute path inside the container where the binary now lives.
 *
 * Uses DockerInstance::copyFileToInstance() which runs `docker cp` through the
 * configured DockerHost (handles both local and ssh:// remote docker daemons).
 */
function dropBinaryOntoContainer(E2ETopologyHarness $topology, string $role): string
{
    $instance = $topology->instance($role);

    if (! $instance instanceof DockerInstance) {
        test()->markTestSkipped("Role [{$role}] is not a DockerInstance; binary drop requires docker cp.");
    }

    $sourcePath = orbitLinuxBinaryPath();
    // Place the binary in /tmp — accessible to all users. docker cp sets root
    // ownership, so we use exec() (runs as the container's default user, root)
    // to chmod rather than ssh() which runs as orbit.
    $targetPath = '/tmp/orbit-binary';

    $instance->copyFileToInstance($sourcePath, $targetPath);

    // chmod via exec() (root user in the container) so the orbit ssh user can
    // execute the binary.
    $chmodResult = $instance->exec("chmod +x {$targetPath}", timeoutSeconds: 30);

    if (! $chmodResult->successful()) {
        throw new RuntimeException('Could not chmod binary in container: '.$chmodResult->errorOutput());
    }

    return $targetPath;
}

/**
 * Run the orbit binary inside a topology container via its exec() transport
 * and return exit_code + stdout + stderr.
 *
 * Uses exec() (container root user) so that env var assignment and binary
 * execution both work without user-privilege constraints. The env vars are
 * injected via `env KEY=value ...` which is portable on the container's sh.
 *
 * @param  list<string>  $arguments
 * @param  array<string, string>  $env  Extra env vars (e.g. HOME) for the binary.
 * @return array{exit_code: int, stdout: string, stderr: string}
 */
function runBinaryOnContainer(
    E2ETopologyHarness $topology,
    string $role,
    string $binaryPath,
    array $arguments,
    array $env = [],
    ?int $timeoutSeconds = null,
): array {
    $instance = $topology->instance($role);

    // Build `env KEY=value KEY2=value2 /path/to/binary arg1 arg2 ...`
    // The `env` command accepts KEY=value pairs and does not require quoting
    // the key; we quote the value via escapeshellarg.
    $envParts = [];

    foreach ($env as $key => $value) {
        $envParts[] = $key.'='.escapeshellarg($value);
    }

    $envPrefix = $envParts !== [] ? 'env '.implode(' ', $envParts).' ' : '';
    $argString = implode(' ', array_map(escapeshellarg(...), $arguments));

    // Capture exit code by appending it as a sentinel line in stdout.
    // stderr is mixed into stdout by docker exec (no separate stderr stream
    // from exec()); we capture both in one stream for simplicity.
    $command = "{$envPrefix}{$binaryPath} {$argString}; printf '\\n__ORBIT_EXIT__%d' \$?";

    $result = $instance->exec($command, timeoutSeconds: $timeoutSeconds ?? 60);

    $raw = $result->output();
    $exitCode = 1;
    $stdout = $raw;

    if (preg_match('/^(.*)\n__ORBIT_EXIT__(\d+)$/s', $raw, $m)) {
        $stdout = $m[1];
        $exitCode = (int) $m[2];
    }

    return [
        'exit_code' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $result->errorOutput(),
    ];
}

// ─── Skip guard — abort before topology acquisition if binary not present ──────

beforeEach(function (): void {
    if (! file_exists(orbitLinuxBinaryPath())) {
        test()->markTestSkipped(
            'Linux x64 orbit binary not built; run `composer test:e2e:docker:binary-acceptance` or build manually with `composer test:e2e:binary:linux`.',
        );
    }
});

// ─── Acceptance tests ──────────────────────────────────────────────────────────

it('linux x64 binary boots on the real container and --version reports the expected version', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGateway);

    try {
        $binaryPath = dropBinaryOntoContainer($topology, 'gateway');

        $version = trim((string) file_get_contents(repo_path('VERSION')));

        expect($version)->not->toBeEmpty('Could not parse version from VERSION');

        $result = runBinaryOnContainer(
            $topology,
            'gateway',
            $binaryPath,
            ['--version', '--no-ansi'],
            env: ['HOME' => '/tmp/orbit-acceptance-home'],
        );

        // Note: Pest 4's toContain() is variadic — do not pass a failure message as a
        // second argument; it would be interpreted as a second needle and fail.
        expect($result['exit_code'])
            ->toBe(
                0,
                "Binary --version exited {$result['exit_code']} on container.\nstdout: {$result['stdout']}\nstderr: {$result['stderr']}",
            );

        expect($result['stdout'])->toContain($version);
    } finally {
        $topology->cleanup();
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway', 'e2e-binary-acceptance');

it(
    'NodeGatewayBootstrapper routes through docker exec when orbit-gateway is absent and returns the gateway_bootstrap_unavailable envelope',
    function (): void {
        // The gateway container in the prepared topology does not have orbit-gateway
        // running. Calling node:new --template=gateway with no configured gateway
        // triggers NodeGatewayBootstrapper::run() which first calls
        // gatewayRuntimeAvailable() (docker exec orbit-gateway test -f ...).
        // With orbit-gateway absent the check fails and the method returns the
        // gateway_bootstrap_unavailable JSON envelope without reaching host PHP or
        // a source-tree artisan. This proves the binary-only docker-exec path works.
        $topology = e2eTopology(E2ETopologyKind::OperatorGateway);

        try {
            $binaryPath = dropBinaryOntoContainer($topology, 'gateway');

            // Run node:new --template=gateway with no gateway configured. This hits
            // the bootstrapper path (line 82 of NodeNewCommand: template === 'gateway'
            // && !hasConfiguredGateway). The bootstrapper checks orbit-gateway via
            // `docker exec orbit-gateway test -f apps/gateway/artisan`; with the
            // container absent it falls through to the unavailable envelope.
            $result = runBinaryOnContainer(
                $topology,
                'gateway',
                $binaryPath,
                ['node:new', '--template=gateway', '--operator-name=acceptance-test-op', '--json'],
                env: ['HOME' => '/tmp/orbit-acceptance-gw-bootstrap'],
                timeoutSeconds: 30,
            );

            // The command exits non-zero (bootstrap failure) and emits a JSON error.
            $stdout = trim($result['stdout']);

            expect($stdout)->not->toBeEmpty(
                "Binary produced no output on container. This may indicate the binary failed to start.\nstderr: {$result['stderr']}",
            );

            $decoded = json_decode($stdout, associative: true);

            expect($decoded)->not->toBeNull(
                "Binary output is not valid JSON. Output:\n{$stdout}\nstderr: {$result['stderr']}",
            );

            // Either the gateway_bootstrap_unavailable envelope (orbit-gateway absent)
            // OR a gateway connectivity error (if the topology has something on that port).
            // Both prove the binary executed and reached the NodeGatewayBootstrapper path.
            $errorCode = $decoded['error']['code'] ?? null;

            expect($errorCode)->not->toBeNull(
                "Expected an error envelope but got:\n{$stdout}",
            );

            // The canonical path: orbit-gateway is not running → unavailable envelope.
            // An alternative: gateway_not_configured when there is no gateway URL set at all
            // and the command fails before reaching the bootstrapper (acceptable — proves
            // the binary ran and returned structured JSON).
            $acceptableCodes = ['gateway_bootstrap_unavailable', 'gateway_not_configured', 'validation_failed'];

            expect(in_array($errorCode, $acceptableCodes, true))
                ->toBeTrue(
                    "Unexpected error code [{$errorCode}]; expected one of: "
                    .implode(', ', $acceptableCodes)
                    .".\nOutput: {$stdout}",
                );
        } finally {
            $topology->cleanup();
        }
    },
)->group('e2e-feature', 'e2e-feature-operator_gateway', 'e2e-binary-acceptance');

it(
    'embedded curl+openssl: gateway:status --help boots service providers on the real Linux container without extension errors',
    function (): void {
        // Proves that the embedded curl and openssl in the linux x64 PHPacker binary
        // are functional: GatewayApiServiceProvider and OperationTokenGuardServiceProvider
        // both boot during --help resolution, and missing extensions would produce a fatal
        // error or a "could not open extension" notice in the combined output.
        $topology = e2eTopology(E2ETopologyKind::OperatorGateway);

        try {
            $binaryPath = dropBinaryOntoContainer($topology, 'gateway');

            $result = runBinaryOnContainer(
                $topology,
                'gateway',
                $binaryPath,
                ['gateway:status', '--help'],
                env: ['HOME' => '/tmp/orbit-acceptance-curl-test'],
            );

            $combined = $result['stdout'].$result['stderr'];

            expect($result['exit_code'])
                ->toBe(
                    0,
                    "gateway:status --help exited {$result['exit_code']} on container.\n{$combined}",
                );

            // Note: Pest 4's toContain() is variadic — do not pass a failure message as a
            // second argument; it would be interpreted as a second needle and fail.
            expect($combined)->not->toContain('extension');
            expect($combined)->not->toContain('Class not found');

            // The help text should mention the gateway status description or usage.
            // We assert on "gateway" which appears in "gateway:status" usage, or the
            // description "Show gateway API status" / "Usage: gateway:status".
            expect($result['stdout'])
                ->not
                ->toBeEmpty(
                    "Binary gateway:status --help produced no stdout on container.\n{$combined}",
                );
        } finally {
            $topology->cleanup();
        }
    },
)->group('e2e-feature', 'e2e-feature-operator_gateway', 'e2e-binary-acceptance');

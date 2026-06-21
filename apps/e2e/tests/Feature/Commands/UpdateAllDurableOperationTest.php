<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyKind;

it('follows a durable update all operation from an operator through gateway event replay', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGateway, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['operator', 'gateway']);

        $port = random_int(18900, 19900);
        $routerPath = '/tmp/orbit-update-all-durable-router.php';
        $logPath = '/tmp/orbit-update-all-durable-requests.log';
        $pidPath = '/tmp/orbit-update-all-durable-router.pid';

        e2ePutRuntimeFile($topology, 'gateway', $routerPath, updateAllDurableOperationRouter($logPath), timeoutSeconds: 60);
        $phpServer = 'p'.'hp -d display_errors=0 -'.'S';
        e2eRunInRoleRuntime(
            $topology,
            'gateway',
            sprintf(
                'rm -f %s %s %s; nohup %s 0.0.0.0:%d %s > /tmp/orbit-update-all-durable-router.out 2>&1 & echo $! > %s',
                escapeshellarg($pidPath),
                escapeshellarg($logPath),
                escapeshellarg('/tmp/orbit-update-all-durable-router.out'),
                $phpServer,
                $port,
                escapeshellarg($routerPath),
                escapeshellarg($pidPath),
            ),
            timeoutSeconds: 60,
        );
        e2eWaitForRuntimeHttpEndpoint($topology, 'gateway', $port, '/api/update/all/start', '/tmp/orbit-update-all-durable-router.out');

        $gatewayUrl = e2eUsesDockerDnsAliasTopology()
            ? "http://gateway:{$port}"
            : 'http://'.$topology->lease()->gatewayApiIp().":{$port}";
        $localInstallRoot = '/tmp/orbit-update-all-local';
        $localBinaryPath = '/tmp/orbit-update-all-binary';

        $topology->ssh(
            'operator',
            sprintf(
                "cat > %s <<'SH'\n#!/usr/bin/env bash\nprintf 'orbit e2e-durable\\n'\nSH\nchmod 0755 %s",
                escapeshellarg($localBinaryPath),
                escapeshellarg($localBinaryPath),
            ),
            timeoutSeconds: 60,
        );

        $result = $topology->ssh(
            'operator',
            implode(' ', [
                'cd /tmp;',
                'rm -rf '.escapeshellarg($localInstallRoot).';',
                'install -d '.escapeshellarg("{$localInstallRoot}/bin").';',
                'env',
                'ORBIT_GATEWAY_URL='.escapeshellarg($gatewayUrl),
                'ORBIT_INSTALL_PATH='.escapeshellarg($localInstallRoot),
                'ORBIT_BIN_PATH='.escapeshellarg("{$localInstallRoot}/orbit"),
                'ORBIT_BINARY_URL=file://'.$localBinaryPath,
                'ORBIT_GATEWAY_OPERATION_FOLLOW_RECONNECT_SLEEP_MS=0',
                'ORBIT_GATEWAY_OPERATION_FOLLOW_MAX_EMPTY_REPLAYS=3',
                'orbit update:all --json',
            ]),
            timeoutSeconds: 180,
        );

        $payload = e2eJsonCommandPayload($result->output());
        $requestLog = e2eRunInRoleRuntime($topology, 'gateway', 'cat '.escapeshellarg($logPath), timeoutSeconds: 30)->output();
        $requests = array_map(
            static fn (string $line): array => json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR),
            array_values(array_filter(array_map(trim(...), explode("\n", $requestLog)))),
        );

        expect($payload)->toBe([
            'event' => 'complete',
            'data' => [
                'exit_code' => 0,
                'data' => [
                    'status' => 'succeeded',
                    'target_version' => 'e2e-durable',
                ],
            ],
        ])
            ->and($requests)->toContain([
                'method' => 'POST',
                'path' => '/api/update/all/start',
                'last_event_id' => null,
            ])
            ->and($requests)->toContain([
                'method' => 'GET',
                'path' => '/api/operations/e2e-update-all/events',
                'last_event_id' => null,
            ])
            ->and($requests)->toContain([
                'method' => 'GET',
                'path' => '/api/operations/e2e-update-all/events',
                'last_event_id' => '1',
            ]);
    } finally {
        if (isset($topology)) {
            e2eRunInRoleRuntime(
                $topology,
                'gateway',
                'test ! -f /tmp/orbit-update-all-durable-router.pid || kill "$(cat /tmp/orbit-update-all-durable-router.pid)" >/dev/null 2>&1 || true',
                timeoutSeconds: 30,
                allowFailure: true,
            );
            $topology->cleanup();
        }
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway', 'e2e-feature-operator-gateway');

it('uses topology candidate cli artifacts for the local update all fan-out', function (): void {
    $topology = e2eTopology(E2ETopologyKind::OperatorGateway, withGatewayApi: true);

    try {
        $topology->withCurrentCheckout(roles: ['operator', 'gateway']);

        $version = trim((string) file_get_contents(repo_path('VERSION')));
        expect($version)->not->toBe('');

        $port = random_int(19901, 20900);
        $routerPath = '/tmp/orbit-update-all-candidate-router.php';
        $logPath = '/tmp/orbit-update-all-candidate-requests.log';
        $pidPath = '/tmp/orbit-update-all-candidate-router.pid';
        $candidateBinaryPath = '/tmp/orbit-update-all-candidate-binary';

        e2ePutRuntimeFile(
            $topology,
            'operator',
            $candidateBinaryPath,
            "#!/usr/bin/env bash\nprintf 'orbit {$version}\\n'\n",
            timeoutSeconds: 60,
        );
        $topology->ssh('operator', 'chmod 0755 '.escapeshellarg($candidateBinaryPath), timeoutSeconds: 60);

        e2ePutRuntimeFile(
            $topology,
            'gateway',
            $routerPath,
            updateAllDurableCandidateOperationRouter($logPath, $version, $candidateBinaryPath),
            timeoutSeconds: 60,
        );

        $phpServer = 'p'.'hp -d display_errors=0 -'.'S';
        e2eRunInRoleRuntime(
            $topology,
            'gateway',
            sprintf(
                'rm -f %s %s %s; nohup %s 0.0.0.0:%d %s > /tmp/orbit-update-all-candidate-router.out 2>&1 & echo $! > %s',
                escapeshellarg($pidPath),
                escapeshellarg($logPath),
                escapeshellarg('/tmp/orbit-update-all-candidate-router.out'),
                $phpServer,
                $port,
                escapeshellarg($routerPath),
                escapeshellarg($pidPath),
            ),
            timeoutSeconds: 60,
        );
        e2eWaitForRuntimeHttpEndpoint($topology, 'gateway', $port, '/api/update/all/start', '/tmp/orbit-update-all-candidate-router.out');

        $gatewayUrl = e2eUsesDockerDnsAliasTopology()
            ? "http://gateway:{$port}"
            : 'http://'.$topology->lease()->gatewayApiIp().":{$port}";
        $localInstallRoot = '/tmp/orbit-update-all-candidate-local';
        $installedCandidateBinary = "{$localInstallRoot}/bin/orbit-binary-{$version}";

        $result = $topology->ssh(
            'operator',
            implode(' ', [
                'cd /tmp;',
                'rm -rf '.escapeshellarg($localInstallRoot).';',
                'install -d '.escapeshellarg("{$localInstallRoot}/bin").';',
                'env',
                'ORBIT_GATEWAY_URL='.escapeshellarg($gatewayUrl),
                'ORBIT_INSTALL_PATH='.escapeshellarg($localInstallRoot),
                'ORBIT_BIN_PATH='.escapeshellarg("{$localInstallRoot}/orbit"),
                'ORBIT_BINARY_URL=file:///tmp/orbit-update-all-candidate-missing',
                'ORBIT_GATEWAY_OPERATION_FOLLOW_RECONNECT_SLEEP_MS=0',
                'ORBIT_GATEWAY_OPERATION_FOLLOW_MAX_EMPTY_REPLAYS=3',
                'orbit update:all --json',
            ]),
            timeoutSeconds: 180,
        );

        $payload = e2eJsonCommandPayload($result->output());
        $installedOutput = $topology->ssh(
            'operator',
            escapeshellarg($installedCandidateBinary).' --version',
            timeoutSeconds: 60,
        )->output();
        $requestLog = e2eRunInRoleRuntime($topology, 'gateway', 'cat '.escapeshellarg($logPath), timeoutSeconds: 30)->output();
        $requests = array_map(
            static fn (string $line): array => json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR),
            array_values(array_filter(array_map(trim(...), explode("\n", $requestLog)))),
        );

        expect($payload['event'])->toBe('complete')
            ->and($payload['data']['exit_code'])->toBe(0)
            ->and($payload['data']['data']['status'])->toBe('succeeded')
            ->and($payload['data']['data']['target_version'])->toBe($version)
            ->and($payload['data']['data']['manifest_source'])->toBe('topology-candidate')
            ->and(trim($installedOutput))->toBe("orbit {$version}")
            ->and($requests)->toContain([
                'method' => 'POST',
                'path' => '/api/update/all/start',
                'last_event_id' => null,
            ])
            ->and($requests)->toContain([
                'method' => 'GET',
                'path' => '/api/operations/e2e-update-all-candidate/events',
                'last_event_id' => null,
            ]);
    } finally {
        if (isset($topology)) {
            e2eRunInRoleRuntime(
                $topology,
                'gateway',
                'test ! -f /tmp/orbit-update-all-candidate-router.pid || kill "$(cat /tmp/orbit-update-all-candidate-router.pid)" >/dev/null 2>&1 || true',
                timeoutSeconds: 30,
                allowFailure: true,
            );
            $topology->cleanup();
        }
    }
})->group('e2e-feature', 'e2e-feature-operator_gateway', 'e2e-feature-operator-gateway');

function updateAllDurableOperationRouter(string $logPath): string
{
    $logPathValue = var_export($logPath, true);

    return <<<PHP
<?php

\$path = parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
\$method = \$_SERVER['REQUEST_METHOD'] ?? 'GET';
\$lastEventId = \$_SERVER['HTTP_LAST_EVENT_ID'] ?? null;

file_put_contents({$logPathValue}, json_encode([
    'method' => \$method,
    'path' => \$path,
    'last_event_id' => \$lastEventId,
], JSON_THROW_ON_ERROR)."\\n", FILE_APPEND);

if (\$method === 'POST' && \$path === '/api/update/all/start') {
    header('Content-Type: application/json');
    http_response_code(202);
    echo json_encode([
        'success' => [
            'data' => [
                'operation_run' => [
                    'id' => 'e2e-update-all',
                    'type' => 'update:all',
                    'status' => 'queued',
                ],
                'update_plan' => [
                    'target_version' => 'e2e-durable',
                    'gateway_image' => 'ghcr.io/hardimpactdev/orbit-gateway:e2e@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                    'manifest_source' => 'github-release',
                    'manifest_version' => 'e2e-durable',
                ],
                'events_url' => '/api/operations/e2e-update-all/events',
            ],
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    return;
}

if (\$method === 'GET' && \$path === '/api/operations/e2e-update-all/events') {
    header('Content-Type: text/event-stream');
    header('X-Accel-Buffering: no');

    if (\$lastEventId === '1') {
        echo "id: 2\\n";
        echo "event: complete\\n";
        echo 'data: {"exit_code":0,"data":{"status":"succeeded","target_version":"e2e-durable"}}'."\\n\\n";

        return;
    }

    echo "id: 1\\n";
    echo "event: step\\n";
    echo 'data: {"message":"gateway replacement started"}'."\\n\\n";

    return;
}

http_response_code(404);
echo 'not found';
PHP;
}

function updateAllDurableCandidateOperationRouter(string $logPath, string $version, string $candidateBinaryPath): string
{
    $logPathValue = var_export($logPath, true);
    $versionValue = var_export($version, true);
    $candidateUrlValue = var_export("file://{$candidateBinaryPath}", true);

    return <<<PHP
<?php

\$path = parse_url(\$_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
\$method = \$_SERVER['REQUEST_METHOD'] ?? 'GET';
\$lastEventId = \$_SERVER['HTTP_LAST_EVENT_ID'] ?? null;
\$version = {$versionValue};
\$candidateUrl = {$candidateUrlValue};

file_put_contents({$logPathValue}, json_encode([
    'method' => \$method,
    'path' => \$path,
    'last_event_id' => \$lastEventId,
], JSON_THROW_ON_ERROR)."\\n", FILE_APPEND);

if (\$method === 'POST' && \$path === '/api/update/all/start') {
    header('Content-Type: application/json');
    http_response_code(202);
    echo json_encode([
        'success' => [
            'data' => [
                'operation_run' => [
                    'id' => 'e2e-update-all-candidate',
                    'type' => 'update:all',
                    'status' => 'queued',
                ],
                'update_plan' => [
                    'target_version' => \$version,
                    'gateway_image' => "ghcr.io/hardimpactdev/orbit-gateway:{\$version}@sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
                    'manifest_source' => 'topology-candidate',
                    'manifest_version' => \$version,
                ],
                'events_url' => '/api/operations/e2e-update-all-candidate/events',
            ],
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

    return;
}

if (\$method === 'GET' && \$path === '/api/operations/e2e-update-all-candidate/events') {
    header('Content-Type: text/event-stream');
    header('X-Accel-Buffering: no');

    echo "id: 1\\n";
    echo "event: complete\\n";
    echo 'data: '.json_encode([
        'exit_code' => 0,
        'data' => [
            'status' => 'succeeded',
            'target_version' => \$version,
            'manifest_source' => 'topology-candidate',
            'cli_artifacts' => [
                'linux-amd64' => [
                    'url' => \$candidateUrl,
                    'sha256' => str_repeat('b', 64),
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\\n\\n";

    return;
}

http_response_code(404);
echo 'not found';
PHP;
}

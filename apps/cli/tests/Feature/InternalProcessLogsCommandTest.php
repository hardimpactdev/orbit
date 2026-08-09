<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Security\OperationTokenSigner;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:halstead
 */
describe('internal process logs command', function (): void {
    beforeEach(function (): void {
        app()->forgetInstance('App\Services\Executor\OperationTokenGuard');
        fakeGateway(fakeSuccessEnvelope([
            'allowed' => true,
        ]));
        $originalPath = getenv('PATH');
        process_logs_original_path($originalPath === false ? '' : $originalPath);

        $originalHome = getenv('HOME');
        process_logs_original_home($originalHome === false ? '' : $originalHome);
    });

    afterEach(function (): void {
        putenv('PATH='.process_logs_original_path());
        putenv('HOME='.process_logs_original_home());

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

        /** @var array{success: array{data: array{output: string}}} $payload */
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

    it('publishes followed docker logs to the operation stream when metadata is provided', function (): void {
        $bin = install_process_logs_fake_bin();

        app()->instance(
            App\Services\GatewayOperationStreamPublisher::class,
            new App\Services\GatewayOperationStreamPublisher(baseUrl: null, timeout: 30),
        );

        Http::fake([
            'https://gateway.test/api/internal-executor/token/verify' => Http::response(fakeSuccessEnvelope([
                'allowed' => true,
            ])),
            'https://gateway.test/api/operations/run-1/stream/publish' => Http::response(fakeSuccessEnvelope([
                'broadcast' => ['delivered' => true],
            ])),
            'https://gateway.test/api/operations/run-1/stream/stop-decision' => Http::response(fakeSuccessEnvelope([
                'should_stop_tail' => false,
            ])),
        ]);

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
                'operation_stream' => [
                    'operation_uuid' => 'run-1',
                    'channel' => 'private-operations.run-1',
                    'gateway_url' => 'https://gateway.test',
                    'ca_pem_path' => null,
                    'publish_endpoint' => '/api/operations/run-1/stream/publish',
                    'stop_decision_endpoint' => '/api/operations/run-1/stream/stop-decision',
                    'publisher_token' => process_logs_publisher_token(),
                ],
            ], JSON_THROW_ON_ERROR),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toBe("Vite ready\n")
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('logs --tail 25 --follow orbit_docs_main_queue');

        Http::assertSent(function (Illuminate\Http\Client\Request $request): bool {
            if (
                $request->method() !== 'POST'
                || $request->url() !== 'https://gateway.test/api/operations/run-1/stream/publish'
            ) {
                return false;
            }

            $frame = $request['frame'];

            if (! is_array($frame) || ! is_string($frame['emitted_at'] ?? null)) {
                return false;
            }

            $expectedFrame = process_logs_operation_stream_fixture();
            $expectedFrame['sequence'] = 1;
            $expectedFrame['emitted_at'] = $frame['emitted_at'];
            $expectedFrame['payload']['data'] = "Vite ready\n";

            return (
                hash_equals(process_logs_publisher_token(), (string) $request['publisher_token'])
                && $frame === $expectedFrame
            );
        });
    });

    it('checks for an active subscriber before starting the follow backend', function (): void {
        $bin = install_process_logs_follow_bin_recording_start();

        app()->instance(
            App\Services\GatewayOperationStreamPublisher::class,
            new App\Services\GatewayOperationStreamPublisher(baseUrl: null, timeout: 30),
        );

        $preStartProbeSawBackend = null;

        Http::fake([
            'https://gateway.test/api/internal-executor/token/verify' => Http::response(fakeSuccessEnvelope([
                'allowed' => true,
            ])),
            'https://gateway.test/api/operations/run-1/stream/publish' => Http::response(fakeSuccessEnvelope([
                'broadcast' => ['delivered' => true],
            ])),
            'https://gateway.test/api/operations/run-1/stream/stop-decision' => function () use (
                &$preStartProbeSawBackend,
                $bin,
            ) {
                if ($preStartProbeSawBackend === null) {
                    $preStartProbeSawBackend = is_file("{$bin}/started.flag");
                }

                return Http::response(fakeSuccessEnvelope([
                    'should_stop_tail' => false,
                    'active_subscribers' => 1,
                ]));
            },
        ]);

        [$exitCode, $output] = run_internal_process_logs_command(
            [
                '--operation-token' => process_logs_signed_operation_token(),
                '--json' => true,
            ],
            json_encode([
                'backend' => 'docker',
                'runtime_unit' => 'orbit_docs_main_queue',
                'lines' => 100,
                'follow' => true,
                'operation_stream' => [
                    'operation_uuid' => 'run-1',
                    'channel' => 'private-operations.run-1',
                    'gateway_url' => 'https://gateway.test',
                    'ca_pem_path' => null,
                    'publish_endpoint' => '/api/operations/run-1/stream/publish',
                    'stop_decision_endpoint' => '/api/operations/run-1/stream/stop-decision',
                    'publisher_token' => process_logs_publisher_token(),
                ],
            ], JSON_THROW_ON_ERROR),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($preStartProbeSawBackend)
            ->toBeFalse()
            ->and(is_file("{$bin}/started.flag"))
            ->toBeTrue()
            ->and(file_get_contents("{$bin}/calls.log"))
            ->toContain('logs --tail 100 --follow orbit_docs_main_queue')
            ->and($output)
            ->toBe("historical-prelude\n");
    });

    it('accepts launchd backend and tails explicit stdout/stderr paths with single tail argv for follow', function (): void {
        $home = sys_get_temp_dir().'/orbit-launchd-home-'.bin2hex(random_bytes(4));
        $logDir = "{$home}/Library/Logs/Orbit/processes";

        mkdir($logDir, permissions: 0o755, recursive: true);
        putenv("HOME={$home}");

        $stdout = "{$logDir}/dev.hardimpact.orbit.test-unit.out.log";
        $stderr = "{$logDir}/dev.hardimpact.orbit.test-unit.err.log";

        if (file_put_contents(filename: $stdout, data: "stdout line 1\n") === false) {
            throw new RuntimeException('Failed to create launchd stdout fixture.');
        }

        if (file_put_contents(filename: $stderr, data: "stderr line 1\n") === false) {
            throw new RuntimeException('Failed to create launchd stderr fixture.');
        }

        try {
            [$exitCode, $output] = run_internal_process_logs_command(
                [
                    '--operation-token' => process_logs_signed_operation_token(),
                    '--json' => true,
                ],
                json_encode([
                    'backend' => 'launchd',
                    'runtime_unit' => 'dev.hardimpact.orbit.test-unit',
                    'stdout_path' => $stdout,
                    'stderr_path' => $stderr,
                    'lines' => 10,
                    'follow' => false,
                ], JSON_THROW_ON_ERROR),
            );

            expect($exitCode)
                ->toBe(0)
                ->and(
                    process_logs_json_success_data($output)['backend'] ?? null,
                )
                ->toBe('launchd');
        } finally {
            if (is_file($stdout)) {
                unlink($stdout);
            }

            if (is_file($stderr)) {
                unlink($stderr);
            }

            delete_process_logs_directory($home);
        }
    });

    it('rejects launchd log paths outside the Orbit user log directory', function (): void {
        $home = sys_get_temp_dir().'/orbit-launchd-home-'.bin2hex(random_bytes(4));

        mkdir("{$home}/Library/Logs/Orbit/processes", permissions: 0o755, recursive: true);
        putenv("HOME={$home}");

        try {
            [$exitCode, $output] = run_internal_process_logs_command(
                [
                    '--operation-token' => process_logs_signed_operation_token(),
                    '--json' => true,
                ],
                json_encode([
                    'backend' => 'launchd',
                    'runtime_unit' => 'dev.hardimpact.orbit.test-unit',
                    'stdout_path' => '/tmp/dev.hardimpact.orbit.test-unit.out.log',
                    'stderr_path' => "{$home}/Library/Logs/Orbit/processes/dev.hardimpact.orbit.test-unit.err.log",
                    'lines' => 10,
                    'follow' => false,
                ], JSON_THROW_ON_ERROR),
            );

            expect($exitCode)
                ->toBe(1)
                ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))
                ->toBe(JsonEnvelope::failure(
                    'validation_failed',
                    'Process logs launchd paths must stay under the Orbit user log directory.',
                    [
                        'field' => 'stdout_path',
                        'reason' => 'launchd_log_path_outside_orbit_directory',
                    ],
                ));
        } finally {
            delete_process_logs_directory($home);
        }
    });
});

/**
 * @return array<string, mixed>
 */
function process_logs_operation_stream_fixture(): array
{
    $contents = file_get_contents(
        dirname(__DIR__, 4).'/packages/core/tests/Fixtures/Operations/node-draft-frame.json',
    );

    if ($contents === false) {
        throw new RuntimeException('Unable to read the node operation stream frame fixture.');
    }

    /** @var array<string, mixed> $frame */
    $frame = json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);

    return $frame;
}

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

function process_logs_publisher_token(): string
{
    return implode('-', ['publisher', 'token']);
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
    /** @var mixed $command */
    $command = Artisan::all()['internal:process-logs'] ?? null;

    if (! $command instanceof SymfonyCommand) {
        throw new RuntimeException('internal:process-logs not registered.');
    }

    $exitCode = $command->run($input, $output);

    return [$exitCode, $output->fetch()];
}

/**
 * @return array<string, mixed>
 */
function process_logs_json_success_data(string $output): array
{
    /** @var mixed $payload */
    $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($payload)) {
        return [];
    }

    /** @var mixed $data */
    $data = data_get(target: $payload, key: 'success.data');

    if (! is_array($data)) {
        return [];
    }

    /** @var array<string, mixed> $data */
    return $data;
}

function process_logs_original_path(?string $path = null): string
{
    static $originalPath = '';

    if ($path !== null) {
        $originalPath = $path;
    }

    return $originalPath;
}

function process_logs_original_home(?string $path = null): string
{
    static $originalHome = '';

    if ($path !== null) {
        $originalHome = $path;
    }

    return $originalHome;
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

function install_process_logs_follow_bin_recording_start(): string
{
    $dir = sys_get_temp_dir().'/orbit-process-logs-bin-'.bin2hex(random_bytes(8));
    mkdir($dir);

    file_put_contents("{$dir}/docker", <<<'PHP'
        #!/usr/bin/env php
        <?php
        file_put_contents(__DIR__.'/calls.log', implode(' ', array_slice($argv, 1)).PHP_EOL, FILE_APPEND);
        file_put_contents(__DIR__.'/started.flag', '1');
        echo "historical-prelude\n";
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
    delete_process_logs_file("{$path}/started.flag");

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

function delete_process_logs_directory(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    $items = scandir($path);

    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $child = "{$path}/{$item}";

        if (is_dir($child)) {
            delete_process_logs_directory($child);

            continue;
        }

        delete_process_logs_file($child);
    }

    rmdir($path);
}

<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Deployments\DeployInstanceRequest;
use Orbit\Sdk\Requests\Deployments\ListInstanceReleasesRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Symfony\Component\Process\Process;
use Tests\Support\SubprocessFixture;

pest()->group('subprocess');

require_once __DIR__.'/../../Support/InstanceSourceOutput.php';

beforeEach(function (): void {
    $this->originalColumns = getenv('COLUMNS');
    putenv('COLUMNS=400');
    MockClient::destroyGlobal();
    $this->orbitHome = sys_get_temp_dir().'/orbit-cli-deployments-'.Str::uuid();
    config()->set('orbit.home', $this->orbitHome);

    app(GatewayConfigRepository::class)->add(new GatewayProfile(
        name: 'test',
        url: 'https://10.44.0.1',
        caPath: '/home/orbit/.orbit/ca/root.pem',
    ));
});

afterEach(function (): void {
    putenv($this->originalColumns === false ? 'COLUMNS' : 'COLUMNS='.$this->originalColumns);
    MockClient::destroyGlobal();
    new Filesystem()->deleteDirectory($this->orbitHome);
});

describe('retained releases', function (): void {
    it('lists the current retained releases through one typed SDK request', function (): void {
        $mock = MockClient::global([
            ListInstanceReleasesRequest::class => deployment_cli_releases_response(),
        ]);

        expect(Artisan::call('instance:release:list', ['instance' => '17']))->toBe(0);
        expect(instance_source_text(Artisan::output()))->toContain(
            'RELEASE SELECTED', 'release-a no', 'release-b yes',
            'Selected release: release-b', 'Request ID: '.deployment_cli_request_id(),
        );

        expect($mock->getLastRequest())
            ->toBeInstanceOf(ListInstanceReleasesRequest::class)
            ->and($mock->getRecordedResponses())
            ->toHaveCount(1);
    });

    it('renders the exact retained-release JSON response', function (): void {
        MockClient::global([
            ListInstanceReleasesRequest::class => deployment_cli_releases_response(),
        ]);

        $this
            ->artisan('instance:release:list', ['instance' => '17', '--json' => true])
            ->expectsOutput(json_encode([
                'releases' => ['release-a', 'release-b'],
                'selected_release' => 'release-b',
                'request_id' => deployment_cli_request_id(),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))
            ->assertExitCode(0);
    });
});

describe('deploy and rollback requests', function (): void {
    it('rejects a missing rollback release before sending a request', function (): void {
        $mock = MockClient::global();

        $exitCode = Artisan::call('instance:rollback', [
            'instance' => '17',
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(trim(Artisan::output()))
            ->toBe(deployment_cli_error(
                'deployment.release_required',
                'A retained release name is required.',
            ))
            ->and($mock->getLastPendingRequest())
            ->toBeNull();
    });

    it('preserves shared safe pre-admission errors and request IDs', function (): void {
        $mock = MockClient::global([
            DeployInstanceRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'deployment.busy',
                    'message' => "Deployment\0is busy.",
                    'details' => ['command' => 'secret-deploy-command'],
                ],
            ], 409, ['X-Orbit-Request-Id' => deployment_cli_request_id()]),
        ]);

        $exitCode = Artisan::call('instance:deploy', [
            'instance' => '17',
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(trim(Artisan::output()))
            ->toBe(deployment_cli_error(
                'deployment.busy',
                'Deployment is busy.',
                deployment_cli_request_id(),
            ))
            ->not->toContain('secret-deploy-command')
            ->and($mock->getRecordedResponses())
            ->toHaveCount(1);
    });

    it('keeps the bounded step of a pre-admission error', function (): void {
        MockClient::global([
            DeployInstanceRequest::class => MockResponse::make([
                'error' => [
                    'code' => 'runtime.convergence_failed',
                    'message' => 'Runtime convergence failed.',
                    'details' => ['step' => 'app-prod-php-runtime-converge', 'command' => 'secret-deploy-command'],
                ],
            ], 502, ['X-Orbit-Request-Id' => deployment_cli_request_id()]),
        ]);

        $exitCode = Artisan::call('instance:deploy', [
            'instance' => '17',
            '--json' => true,
            '--no-interaction' => true,
        ]);

        expect($exitCode)->toBe(1)
            ->and(json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR))
            ->toBe(['error' => [
                'code' => 'runtime.convergence_failed',
                'message' => 'Runtime convergence failed.',
                'details' => ['step' => 'app-prod-php-runtime-converge'],
                'request_id' => deployment_cli_request_id(),
            ]]);
    });

    it('leaves SIGINT at its default while a deploy, rollback, or release request is sent', function (): void {
        $commands = Artisan::all();

        expect($commands['instance:deploy']->getSubscribedSignals())->toBe([])
            ->and($commands['instance:rollback']->getSubscribedSignals())->toBe([])
            ->and($commands['instance:release:list']->getSubscribedSignals())->toBe([]);
    });

    it('does not register the replaced release-list name', function (): void {
        expect(Artisan::all())->not->toHaveKeys(['instance:releases']);
    });

    it('interrupts a synchronous Gateway request before response headers arrive', function (int $signal, string $mode): void {
        if (! function_exists('pcntl_signal_dispatch') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX signals are unavailable.');
        }

        $fixture = deployment_cli_signal_fixture($this->orbitHome, 'headers-late');
        $command = new Process(
            [PHP_BINARY, dirname(__DIR__, 3).'/orbit', 'node:role:add', '17', 'app-prod', '--converge', $mode],
            env: ['ORBIT_HOME' => $fixture['home']],
            timeout: $fixture['deadline'] - microtime(true),
        );

        try {
            $command->start();
            SubprocessFixture::waitUntil($fixture['server'], static fn (): bool => is_file($fixture['request']));
            expect($command->isRunning())->toBeTrue();
            $startedAt = microtime(true);
            $command->signal($signal);
            $exitCode = $command->wait();
            $elapsed = microtime(true) - $startedAt;
            expect($exitCode)->toBe(128 + $signal)
                ->and($elapsed)->toBeLessThan(2.0);
            SubprocessFixture::waitUntil($fixture['server'], static fn (): bool => is_file($fixture['disconnected']));

            expect($fixture['server']->wait())->toBe(0)
                ->and($command->getErrorOutput())->toBe('')
                ->and($command->getOutput())->not->toContain('Role [app-prod] added')
                ->and($command->getOutput())->not->toContain('Added Node role.');

            if ($mode === '--json') {
                expect($command->getOutput())->toBe('');
            } else {
                expect($command->getOutput())->toContain('Operation interrupted.');
            }
        } finally {
            if ($command->isRunning()) {
                $command->stop(0.1, 9);
            }

            if ($fixture['server']->isRunning()) {
                $fixture['server']->stop(0.1, 9);
            }
        }
    })->with([SIGINT, SIGTERM])->with(['--ansi', '--no-ansi', '--json']);

    it('terminates a rollback start promptly and disconnects before headers arrive', function (): void {
        if (! defined('SIGINT') || ! function_exists('posix_kill') || ! function_exists('openssl_csr_new')) {
            $this->markTestSkipped('POSIX signals are unavailable.');
        }

        $fixture = deployment_cli_signal_fixture($this->orbitHome, 'headers-late');
        $output = '';
        $command = new Process(
            [PHP_BINARY, dirname(__DIR__, 3).'/orbit', 'instance:rollback', '17', '--release=release-a', '--json', '--no-interaction'],
            env: ['ORBIT_HOME' => $fixture['home']],
            timeout: $fixture['deadline'] - microtime(true),
        );

        try {
            $command->start(static function (string $type, string $data) use (&$output): void {
                $output .= $data;
            });
            SubprocessFixture::waitUntil($fixture['server'], static fn (): bool => is_file($fixture['request']));

            if (! $command->isRunning()) {
                throw new RuntimeException(
                    "Deployment command exited before SIGINT: {$output}\n"
                    .'Request: '.file_get_contents($fixture['request'])."\n"
                    .'Server stdout: '.$fixture['server']->getOutput()."\n"
                    .'Server stderr: '.$fixture['server']->getErrorOutput(),
                );
            }
            $startedAt = microtime(true);
            $command->signal(SIGINT);
            $exitCode = $command->wait();
            $elapsed = microtime(true) - $startedAt;
            SubprocessFixture::waitUntil($fixture['server'], static fn (): bool => is_file($fixture['disconnected']));
            $serverExitCode = $fixture['server']->wait();

            expect($exitCode)->not->toBe(0)
                ->and($command->getTermSignal())->toBe(SIGINT)
                ->and($elapsed)->toBeLessThan(2.0)
                ->and($serverExitCode)->toBe(0)
                ->and($output)->not->toContain('succeeded');
        } finally {
            if ($command->isRunning()) {
                $command->stop(0.1, 9);
            }

            if ($fixture['server']->isRunning()) {
                $fixture['server']->stop(0.1, 9);
            }
        }
    });

});

function deployment_cli_releases_response(): MockResponse
{
    return MockResponse::make([
        'data' => [
            'releases' => ['release-a', 'release-b'],
            'selected_release' => 'release-b',
        ],
        'meta' => ['request_id' => deployment_cli_request_id()],
    ]);
}

function deployment_cli_error(string $code, string $message, ?string $requestId = null): string
{
    return json_encode([
        'error' => [
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId,
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function deployment_cli_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a847';
}

/**
 * @return array{
 *     home: string,
 *     request: string,
 *     disconnected: string,
 *     server: Process,
 *     deadline: float,
 * }
 */
function deployment_cli_signal_fixture(string $root, string $mode): array
{
    $directory = "{$root}/signal-{$mode}";
    mkdir($directory, 0o700, recursive: true);
    [$certificate, $privateKey] = deployment_cli_signal_certificate($directory);
    $request = "{$directory}/request";
    $disconnected = "{$directory}/disconnected";
    $server = new Process([
        PHP_BINARY,
        '-r',
        <<<'PHP'
            $context = stream_context_create(['ssl' => [
                'local_cert' => $argv[1],
                'local_pk' => $argv[2],
                'verify_peer' => false,
            ]]);
            $server = stream_socket_server(
                'tls://127.0.0.1:0',
                $errorNumber,
                $errorMessage,
                STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
                $context,
            );

            if ($server === false) {
                fwrite(STDERR, $errorMessage);
                exit($errorNumber ?: 1);
            }

            $address = stream_socket_get_name($server, false);

            if (! is_string($address)) {
                exit(2);
            }

            fwrite(STDOUT, $address."\n");
            fflush(STDOUT);
            $connection = stream_socket_accept($server, -1);

            if ($connection === false) {
                exit(3);
            }

            $request = '';

            while (! str_contains($request, "\r\n\r\n")) {
                $chunk = fread($connection, 8192);

                if (! is_string($chunk) || $chunk === '') {
                    exit(4);
                }

                $request .= $chunk;
            }

            file_put_contents($argv[3], $request);

            stream_set_blocking($connection, false);
            while (true) {
                $read = [$connection];
                $write = null;
                $except = null;

                if (stream_select($read, $write, $except, 0, 100_000) === 1) {
                    $data = fread($connection, 8192);

                    if ($data === '' && feof($connection)) {
                        file_put_contents($argv[4], 'closed');
                        fclose($connection);
                        fclose($server);
                        exit(0);
                    }
                }
            }

            PHP,
        $certificate,
        $privateKey,
        $request,
        $disconnected,
    ], timeout: 10);
    $server->start();
    $deadline = $server->getStartTime() + $server->getTimeout();
    $address = SubprocessFixture::line($server);

    $home = "{$directory}/home";
    mkdir($home, 0o700);
    new GatewayConfigRepository("{$home}/config.json")->add(new GatewayProfile(
        name: 'signal',
        url: "https://{$address}",
        caPath: $certificate,
    ));

    return compact('home', 'request', 'disconnected', 'server', 'deadline');
}

/** @return array{string, string} */
function deployment_cli_signal_certificate(string $directory): array
{
    $configuration = "{$directory}/openssl.cnf";
    file_put_contents($configuration, <<<'OPENSSL'
        [req]
        distinguished_name = subject
        x509_extensions = v3_ca
        prompt = no

        [subject]
        CN = 127.0.0.1

        [v3_ca]
        subjectAltName = IP:127.0.0.1
        basicConstraints = critical, CA:TRUE
        keyUsage = critical, keyCertSign, digitalSignature
        OPENSSL);
    $key = openssl_pkey_new([
        'config' => $configuration,
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    $certificate = openssl_csr_sign(
        openssl_csr_new(
            ['commonName' => '127.0.0.1'],
            $key,
            ['config' => $configuration],
        ),
        null,
        $key,
        1,
        ['config' => $configuration, 'x509_extensions' => 'v3_ca'],
    );
    openssl_x509_export($certificate, $certificatePem);
    openssl_pkey_export($key, $privateKeyPem, null, ['config' => $configuration]);
    $certificatePath = "{$directory}/ca.pem";
    $privateKeyPath = "{$directory}/key.pem";
    file_put_contents($certificatePath, $certificatePem);
    file_put_contents($privateKeyPath, $privateKeyPem);
    chmod($certificatePath, 0o600);
    chmod($privateKeyPath, 0o600);

    return [$certificatePath, $privateKeyPath];
}

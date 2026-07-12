<?php

declare(strict_types=1);

use App\Exceptions\GatewayApiException;
use App\Exceptions\GatewayApiFailureKind;
use App\Services\GatewayApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Progress\ForkedFrameTicker;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

describe('GatewayApiClient', function (): void {
    it('returns decoded arrays from get requests', function (): void {
        Http::fake([
            'https://gateway.test/api/me*' => Http::response(['self' => ['name' => 'operator']], 200),
        ]);

        $result = new GatewayApiClient('https://gateway.test', 30)
            ->get('/api/me', ['include' => 'permissions']);

        expect($result)->toBe(['self' => ['name' => 'operator']]);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'GET'
                && str_starts_with($request->url(), 'https://gateway.test/api/me')
                && str_contains($request->url(), 'include=permissions')
            ),
        );
    });

    it('uses the stubbed blocking post path when the HTTP client is faked', function (): void {
        Http::fake([
            'https://gateway.test/api/update/all/start' => Http::response(['started' => true], 200),
        ]);

        $tickCount = 0;
        $ticker = new ForkedFrameTicker(50_000);
        $ticker->start(function () use (&$tickCount): void {
            $tickCount++;
        });

        try {
            $result = new GatewayApiClient('https://gateway.test', 30)
                ->postWithIdleTicks('/api/update/all/start');

            expect($result)->toBe(['started' => true]);
        } finally {
            $ticker->stop();
        }

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/update/all/start'
            ),
        );
    });

    it('invokes progress idle callbacks while waiting for a slow post response', function (): void {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($server === false) {
            test()->markTestSkipped('stream_socket_server is required for delayed gateway post coverage.');
        }

        $address = stream_socket_get_name($server, false);
        $port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);

        $serverPid = pcntl_fork();

        if ($serverPid === -1) {
            fclose($server);

            test()->markTestSkipped('pcntl_fork is required for delayed gateway post coverage.');
        }

        if ($serverPid === 0) {
            $connection = stream_socket_accept($server);

            if (is_resource($connection)) {
                while (! feof($connection)) {
                    $chunk = fread($connection, 8192);

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    if (str_contains($chunk, "\r\n\r\n")) {
                        break;
                    }
                }

                usleep(100_000);
                $body = json_encode(['started' => true], JSON_THROW_ON_ERROR);
                fwrite(
                    $connection,
                    "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: "
                    .strlen($body)
                    ."\r\nConnection: close\r\n\r\n{$body}",
                );
                fclose($connection);
            }

            fclose($server);
            terminateForkedFixtureProcess();
        }

        $tickCount = 0;
        $ticker = new ForkedFrameTicker(30_000);
        $ticker->start(function () use (&$tickCount): void {
            $tickCount++;
        });

        try {
            $result = new GatewayApiClient("http://127.0.0.1:{$port}", 30)
                ->postWithIdleTicks('/api/update/all/start');

            expect($result)->toBe(['started' => true])->and($tickCount)->toBeGreaterThanOrEqual(2);
        } finally {
            $ticker->stop();
            pcntl_waitpid($serverPid, $status);
            fclose($server);
        }
    });

    it('keeps idle callbacks on cadence while waiting for a slow post response without fork signals', function (): void {
        if (! function_exists('curl_multi_init')) {
            test()->markTestSkipped('curl_multi_init is required for no-fork gateway post coverage.');
        }

        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($server === false) {
            test()->markTestSkipped('stream_socket_server is required for delayed gateway post coverage.');
        }

        $address = stream_socket_get_name($server, false);
        $port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);

        $serverPid = pcntl_fork();

        if ($serverPid === -1) {
            fclose($server);

            test()->markTestSkipped('pcntl_fork is required for delayed gateway post coverage.');
        }

        if ($serverPid === 0) {
            $connection = stream_socket_accept($server);

            if (is_resource($connection)) {
                while (! feof($connection)) {
                    $chunk = fread($connection, 8192);

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    if (str_contains($chunk, "\r\n\r\n")) {
                        break;
                    }
                }

                usleep(120_000);
                $body = json_encode(['started' => true], JSON_THROW_ON_ERROR);
                fwrite(
                    $connection,
                    "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: "
                    .strlen($body)
                    ."\r\nConnection: close\r\n\r\n{$body}",
                );
                fclose($connection);
            }

            fclose($server);
            terminateForkedFixtureProcess();
        }

        $ticks = [];
        $cleanupIdleCallback = registerGatewayClientIdleCallbackWithoutFork(30_000, function () use (&$ticks): void {
            $ticks[] = hrtime(true);
        });

        try {
            putenv('ORBIT_GATEWAY_IDLE_POST_DISABLE_FORK=1');

            $result = new GatewayApiClient("http://127.0.0.1:{$port}", 30)
                ->postWithIdleTicks('/api/update/all/start');

            expect($result)->toBe(['started' => true])->and(count($ticks))->toBeGreaterThanOrEqual(3);

            $maxGapMicroseconds = max(array_map(
                static fn (array $pair): int => intdiv($pair[1] - $pair[0], 1000),
                array_map(null, array_slice($ticks, 0, -1), array_slice($ticks, 1)),
            ));

            expect($maxGapMicroseconds)->toBeLessThan(150_000);
        } finally {
            putenv('ORBIT_GATEWAY_IDLE_POST_DISABLE_FORK');
            $cleanupIdleCallback();
            pcntl_waitpid($serverPid, $status);
            fclose($server);
        }
    });

    it('closes the curl multi handler before PHP shutdown after idle tick requests resolve', function (): void {
        $script = <<<'PHP'
            declare(strict_types=1);

            use App\Services\GatewayApiClient;
            use Illuminate\Contracts\Console\Kernel;
            use Orbit\Core\Progress\ForkedFrameTicker;

            require __DIR__.'/vendor/autoload.php';
            $app = require __DIR__.'/bootstrap/app.php';
            $app->make(Kernel::class)->bootstrap();

            $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

            if ($server === false) {
                fwrite(STDERR, 'server unavailable');
                exit(2);
            }

            $address = stream_socket_get_name($server, false);
            $port = (int) substr((string) $address, strrpos((string) $address, ':') + 1);
            $serverPid = pcntl_fork();

            if ($serverPid === -1) {
                fclose($server);
                fwrite(STDERR, 'fork unavailable');
                exit(2);
            }

            if ($serverPid === 0) {
                $connection = stream_socket_accept($server);

                if (is_resource($connection)) {
                    while (! feof($connection)) {
                        $chunk = fread($connection, 8192);

                        if ($chunk === false || $chunk === '' || str_contains($chunk, "\r\n\r\n")) {
                            break;
                        }
                    }

                    $body = json_encode(['started' => true], JSON_THROW_ON_ERROR);
                    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\nConnection: close\r\n\r\n{$body}");
                    fclose($connection);
                }

                fclose($server);
                exit(0);
            }

            $ticker = new ForkedFrameTicker(50_000);
            $ticker->start(static function (): void {});

            try {
                $result = new GatewayApiClient("http://127.0.0.1:{$port}", 30)
                    ->postWithIdleTicks('/api/update/all/start');

                if ($result !== ['started' => true]) {
                    fwrite(STDERR, 'unexpected result');
                    exit(1);
                }
            } finally {
                $ticker->stop();
                posix_kill($serverPid, SIGTERM);
                pcntl_waitpid($serverPid, $status);
                fclose($server);
            }

            echo "done\n";
            PHP;

        $process = new Process([PHP_BINARY, '-r', $script], base_path());
        $process->setTimeout(3);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $this->fail('GatewayApiClient idle tick request process timed out during PHP shutdown.');
        }

        expect($process->isSuccessful())
            ->toBeTrue($process->getErrorOutput())
            ->and($process->getOutput())
            ->toContain('done');
    });

    it('returns decoded arrays from post requests and sends JSON payloads', function (): void {
        Http::fake([
            'https://gateway.test/api/nodes' => Http::response(['created' => true], 200),
        ]);

        $result = new GatewayApiClient('https://gateway.test', 30)
            ->post('/api/nodes', ['name' => 'app-dev']);

        expect($result)->toBe(['created' => true]);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/nodes'
                && $request->isJson()
                && $request['name'] === 'app-dev'
            ),
        );
    });

    it('returns decoded arrays from patch requests and sends JSON payloads', function (): void {
        Http::fake([
            'https://gateway.test/api/processes/vite' => Http::response(['updated' => true], 200),
        ]);

        $result = new GatewayApiClient('https://gateway.test', 30)
            ->patch('/api/processes/vite', ['command' => 'npm run dev']);

        expect($result)->toBe(['updated' => true]);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'PATCH'
                && $request->url() === 'https://gateway.test/api/processes/vite'
                && $request->isJson()
                && $request['command'] === 'npm run dev'
            ),
        );
    });

    it('throws gateway api exceptions for client errors with the status code', function (): void {
        Http::fake([
            'https://gateway.test/api/missing' => Http::response(['error' => ['message' => 'Missing']], 404),
        ]);

        $exception = captureGatewayException(
            fn () => new GatewayApiClient('https://gateway.test', 30)
                ->get('/api/missing'),
        );

        expect($exception)
            ->toBeInstanceOf(GatewayApiException::class)
            ->and($exception?->statusCode())
            ->toBe(404)
            ->and($exception?->getMessage())
            ->toContain('HTTP 404')
            ->and($exception?->getMessage())
            ->toContain('Missing');
    });

    it('throws gateway api exceptions for server errors', function (): void {
        Http::fake([
            'https://gateway.test/api/status' => Http::response('gateway unavailable', 503),
        ]);

        $exception = captureGatewayException(
            fn () => new GatewayApiClient('https://gateway.test', 30)
                ->get('/api/status'),
        );

        expect($exception)
            ->toBeInstanceOf(GatewayApiException::class)
            ->and($exception?->statusCode())
            ->toBe(503)
            ->and($exception?->getMessage())
            ->toContain('HTTP 503');
    });

    it('throws gateway api exceptions for generic network errors', function (): void {
        Http::fake(function () {
            throw new ConnectionException('connection refused');
        });

        $exception = captureGatewayException(
            fn () => new GatewayApiClient('https://gateway.test', 30)
                ->get('/api/me'),
        );

        expect($exception)
            ->toBeInstanceOf(GatewayApiException::class)
            ->and($exception?->statusCode())
            ->toBeNull()
            ->and($exception?->failureKind())
            ->toBe(GatewayApiFailureKind::Network)
            ->and($exception?->cliFailureCode())
            ->toBe('gateway_unavailable')
            ->and($exception?->getMessage())
            ->toContain('Gateway request failed');
    });

    it('surfaces wireguard_unreachable failure when the gateway is not reachable on the WireGuard route', function (): void {
        Http::fake(function () {
            throw new ConnectionException('Connection timed out after 30 seconds');
        });

        $exception = captureGatewayException(
            fn () => new GatewayApiClient('https://10.6.0.1', 30)
                ->get('/api/me'),
        );

        expect($exception)
            ->toBeInstanceOf(GatewayApiException::class)
            ->and($exception?->failureKind())
            ->toBe(GatewayApiFailureKind::WireguardUnreachable)
            ->and($exception?->cliFailureCode())
            ->toBe('gateway_unreachable_wireguard')
            ->and($exception?->getMessage())
            ->toContain('Gateway unreachable over WireGuard');
    });

    it('classifies no route to host failures as wireguard unreachable', function (): void {
        Http::fake(function () {
            throw new ConnectionException('Failed to connect: no route to host');
        });

        $exception = captureGatewayException(
            fn () => new GatewayApiClient('https://10.6.0.1', 30)
                ->get('/api/me'),
        );

        expect($exception)
            ->toBeInstanceOf(GatewayApiException::class)
            ->and($exception?->failureKind())
            ->toBe(GatewayApiFailureKind::WireguardUnreachable)
            ->and($exception?->cliFailureCode())
            ->toBe('gateway_unreachable_wireguard');
    });

    it('never sends an Authorization header (D2: no bearer identity)', function (): void {
        Http::fake([
            'https://gateway.test/api/me' => Http::response(['success' => ['data' => [], 'meta' => []]], 200),
        ]);

        new GatewayApiClient('https://gateway.test', 30)
            ->get('/api/me');

        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
    });

    it('does not send a public node transport preference header', function (): void {
        Http::fake([
            'https://gateway.test/api/me' => Http::response(['success' => ['data' => [], 'meta' => []]], 200),
        ]);

        new GatewayApiClient('https://gateway.test', 30)->get('/api/me');

        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('X-Orbit-Node-Transport-Preference'));
    });

    it('resolves baseUrl + timeout from the provider binding (env-only config + JSON fallback in provider closure)', function (): void {
        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 12);
        app()->forgetInstance(GatewayApiClient::class);

        $timeout = null;

        Http::fake(function (Request $request, array $options) use (&$timeout) {
            $timeout = $options['timeout'] ?? null;

            return Http::response(['success' => ['data' => [], 'meta' => []]], 200);
        });

        $result = app(GatewayApiClient::class)->get('/api/me');

        expect($result)
            ->toBe(['success' => ['data' => [], 'meta' => []]])
            ->and($timeout)
            ->toBe(12);
    });

    it('can raise the timeout for long gateway requests without mutating the base client', function (): void {
        $timeouts = [];

        Http::fake(function (Request $request, array $options) use (&$timeouts) {
            $timeouts[] = $options['timeout'] ?? null;

            return Http::response(['ok' => true], 200);
        });

        $client = new GatewayApiClient('https://gateway.test', 30);

        $client->withMinimumTimeout(300)->post('/api/slow', []);
        $client->get('/api/fast');

        expect($timeouts)->toBe([300, 30]);
    });

    it('resolves the gateway CA PEM path from the provider binding and verifies against it', function (): void {
        $pemPath = tempnam(sys_get_temp_dir(), 'orbit-ca-').'.pem';
        file_put_contents($pemPath, "-----BEGIN CERTIFICATE-----\nfake\n-----END CERTIFICATE-----\n");

        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        config()->set('orbit.gateway.ca_pem_path', $pemPath);
        app()->forgetInstance(GatewayApiClient::class);

        $verify = null;

        Http::fake(function (Request $request, array $options) use (&$verify) {
            $verify = $options['verify'] ?? null;

            return Http::response(['success' => ['data' => [], 'meta' => []]], 200);
        });

        try {
            app(GatewayApiClient::class)->get('/api/me');
        } finally {
            @unlink($pemPath);
        }

        expect($verify)->toBe($pemPath);
    });

    it('verifies TLS against the configured gateway CA when a PEM file exists', function (): void {
        $pemPath = tempnam(sys_get_temp_dir(), 'orbit-ca-').'.pem';
        file_put_contents($pemPath, "-----BEGIN CERTIFICATE-----\nfake\n-----END CERTIFICATE-----\n");

        $verify = false;

        Http::fake(function (Request $request, array $options) use (&$verify) {
            $verify = $options['verify'] ?? null;

            return Http::response(['ok' => true], 200);
        });

        try {
            new GatewayApiClient('https://gateway.test', 30, $pemPath)
                ->get('/api/me');
        } finally {
            @unlink($pemPath);
        }

        expect($verify)->toBe($pemPath);
    });

    it('leaves the default verify behavior when no CA PEM path is configured', function (): void {
        $verify = null;

        Http::fake(function (Request $request, array $options) use (&$verify) {
            $verify = $options['verify'] ?? null;

            return Http::response(['ok' => true], 200);
        });

        new GatewayApiClient('https://gateway.test', 30)
            ->get('/api/me');

        // The default Guzzle behavior leaves verify as a boolean (true), never a CA path string.
        expect($verify)->not->toBeString();
    });

    it('leaves the default verify behavior when the CA PEM path points at a missing file', function (): void {
        $missingPath = sys_get_temp_dir().'/orbit-missing-'.bin2hex(random_bytes(6)).'.pem';

        $verify = null;

        Http::fake(function (Request $request, array $options) use (&$verify) {
            $verify = $options['verify'] ?? null;

            return Http::response(['ok' => true], 200);
        });

        new GatewayApiClient('https://gateway.test', 30, $missingPath)
            ->get('/api/me');

        expect($verify)->not->toBeString();
    });
});

function captureGatewayException(callable $callback): ?GatewayApiException
{
    try {
        $callback();
    } catch (GatewayApiException $exception) {
        return $exception;
    }

    return null;
}

/**
 * @return Closure(): void
 */
function registerGatewayClientIdleCallbackWithoutFork(int $intervalUs, callable $callback): Closure
{
    $reflection = new ReflectionClass(ForkedFrameTicker::class);
    $idleCallback = $reflection->getProperty('idleCallback');
    $idleIntervalMicroseconds = $reflection->getProperty('idleIntervalMicroseconds');
    $lastInvokedAt = $reflection->getProperty('lastInvokedAt');

    $previousIdleCallback = $idleCallback->getValue(null);
    $previousIdleIntervalMicroseconds = $idleIntervalMicroseconds->getValue(null);
    $previousLastInvokedAt = $lastInvokedAt->getValue(null);

    $idleCallback->setValue(null, $callback);
    $idleIntervalMicroseconds->setValue(null, $intervalUs);
    $lastInvokedAt->setValue(null, 0.0);

    return function () use (
        $idleCallback,
        $idleIntervalMicroseconds,
        $lastInvokedAt,
        $previousIdleCallback,
        $previousIdleIntervalMicroseconds,
        $previousLastInvokedAt,
    ): void {
        $idleCallback->setValue(null, $previousIdleCallback);
        $idleIntervalMicroseconds->setValue(null, $previousIdleIntervalMicroseconds);
        $lastInvokedAt->setValue(null, $previousLastInvokedAt);
    };
}

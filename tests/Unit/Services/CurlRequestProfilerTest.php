<?php

declare(strict_types=1);

use App\Services\CurlRequestProfiler;

it('profiles a completed local http request', function (): void {
    $documentRoot = sys_get_temp_dir().'/orbit-curl-profiler-test';

    if (! is_dir($documentRoot)) {
        mkdir($documentRoot, 0777, true);
    }

    file_put_contents($documentRoot.'/index.php', <<<'PHP'
<?php
header('Content-Type: text/plain');
echo 'profile-ok';
PHP);

    [$process, $pipes, $port] = startProfileTestServer($documentRoot);

    try {
        $result = app(CurlRequestProfiler::class)->profile("http://127.0.0.1:{$port}/index.php?probe=1");

        expect($result['request']['method'])->toBe('GET')
            ->and($result['request']['url'])->toBe("http://127.0.0.1:{$port}/index.php?probe=1")
            ->and($result['request']['uri'])->toBe('/index.php?probe=1')
            ->and($result['request']['status'])->toBe(200)
            ->and($result['request']['bytes'])->toBeGreaterThan(0)
            ->and($result['request']['completed'])->toBeTrue()
            ->and($result['timings']['dns_ms'])->toBeGreaterThanOrEqual(0.0)
            ->and($result['timings']['connect_ms'])->toBeGreaterThanOrEqual(0.0)
            ->and($result['timings']['tls_ms'])->toBeGreaterThanOrEqual(0.0)
            ->and($result['timings']['ttfb_ms'])->toBeGreaterThanOrEqual(0.0)
            ->and($result['timings']['download_ms'])->toBeGreaterThanOrEqual(0.0)
            ->and($result['timings']['total_ms'])->toBeGreaterThanOrEqual(0.0)
            ->and($result['response_headers']['content-type'])->toContain('text/plain')
            ->and($result['error'])->toBeNull();
    } finally {
        stopProfileTestServer($process, $pipes);
    }
});

/**
 * @return array{resource, array<int, resource>, int}
 */
function startProfileTestServer(string $documentRoot): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

    if ($socket === false) {
        throw new RuntimeException("Failed to reserve a port: {$errorMessage}");
    }

    $address = stream_socket_get_name($socket, false);
    fclose($socket);

    $port = (int) substr((string) $address, (int) strrpos((string) $address, ':') + 1);
    $command = sprintf(
        'exec %s -S 127.0.0.1:%d -t %s',
        escapeshellarg(PHP_BINARY),
        $port,
        escapeshellarg($documentRoot),
    );

    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Failed to start PHP HTTP server.');
    }

    $deadline = microtime(true) + 5.0;

    do {
        $status = proc_get_status($process);

        if (! $status['running']) {
            $stderr = stream_get_contents($pipes[2]);
            stopProfileTestServer($process, $pipes);

            throw new RuntimeException('PHP HTTP server exited early: '.trim($stderr));
        }

        $probe = @fsockopen('127.0.0.1', $port, $connectErrorCode, $connectErrorMessage, 0.1);

        if (is_resource($probe)) {
            fclose($probe);

            return [$process, $pipes, $port];
        }

        usleep(100_000);
    } while (microtime(true) < $deadline);

    stopProfileTestServer($process, $pipes);

    throw new RuntimeException('Timed out waiting for the PHP HTTP server to start.');
}

/**
 * @param  resource  $process
 * @param  array<int, resource>  $pipes
 */
function stopProfileTestServer($process, array $pipes): void
{
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }

    proc_terminate($process);
    proc_close($process);
}

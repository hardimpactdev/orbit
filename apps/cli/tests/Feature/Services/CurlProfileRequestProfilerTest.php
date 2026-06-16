<?php

declare(strict_types=1);

use App\Services\Profile\ProfileRequestProfiler;

describe(ProfileRequestProfiler::class, function (): void {
    it('uses the active gateway timeout for slow caller-side profiles', function (): void {
        $server = startSlowCurlProfileHttpTestServer();

        config()->set('orbit.gateway.timeout', 5);
        app()->forgetInstance(ProfileRequestProfiler::class);

        try {
            $profile = app(ProfileRequestProfiler::class)
                ->profile("http://127.0.0.1:{$server['port']}/");
        } finally {
            stopSlowCurlProfileHttpTestServer($server);
        }

        expect($profile['request']['completed'])->toBeTrue()
            ->and($profile['request']['status'])->toBe(200)
            ->and($profile['error'])->toBeNull()
            ->and($profile['timings']['total_ms'])->toBeGreaterThan(3000.0);
    });

    it('trusts the active gateway CA PEM for caller-side HTTPS profiles', function (): void {
        if (! curlProfileOpenSslAvailable()) {
            $this->markTestSkipped('The openssl CLI is required for this TLS profile test.');
        }

        $server = startCurlProfileHttpsTestServer();

        config()->set('orbit.gateway.ca_pem_path', $server['ca_pem']);
        app()->forgetInstance(ProfileRequestProfiler::class);

        try {
            $profile = app(ProfileRequestProfiler::class)
                ->profile("https://localhost:{$server['port']}/");
        } finally {
            stopCurlProfileHttpsTestServer($server);
        }

        expect($profile['request']['completed'])->toBeTrue()
            ->and($profile['request']['status'])->toBe(200)
            ->and($profile['error'])->toBeNull();
    });
});

/**
 * @return array{process: resource, directory: string, port: int}
 */
function startSlowCurlProfileHttpTestServer(): array
{
    $directory = sys_get_temp_dir().'/orbit-curl-profile-slow-'.bin2hex(random_bytes(4));

    if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new RuntimeException("Unable to create test directory: {$directory}");
    }

    file_put_contents("{$directory}/index.php", <<<'PHP'
<?php

usleep(4_000_000);

header('Content-Type: text/plain');

echo 'profile-ok';
PHP);

    $port = unusedCurlProfileTestPort();
    $process = proc_open(
        [
            PHP_BINARY,
            '-S',
            "127.0.0.1:{$port}",
            '-t',
            $directory,
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ],
        $pipes,
        $directory,
    );

    if (! is_resource($process)) {
        removeCurlProfileTestDirectory($directory);

        throw new RuntimeException('Unable to start slow profile test server.');
    }

    fclose($pipes[0]);

    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);

        if (is_resource($socket)) {
            fclose($socket);

            return [
                'process' => $process,
                'directory' => $directory,
                'port' => $port,
            ];
        }

        usleep(100_000);
    }

    stopSlowCurlProfileHttpTestServer([
        'process' => $process,
        'directory' => $directory,
        'port' => $port,
    ]);

    throw new RuntimeException('Slow profile test server did not become ready.');
}

/**
 * @param  array{process: resource, directory: string, port: int}  $server
 */
function stopSlowCurlProfileHttpTestServer(array $server): void
{
    proc_terminate($server['process']);
    proc_close($server['process']);
    removeCurlProfileTestDirectory($server['directory']);
}

function curlProfileOpenSslAvailable(): bool
{
    $process = @proc_open(
        ['openssl', 'version'],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
    );

    if (! is_resource($process)) {
        return false;
    }

    foreach ($pipes as $pipe) {
        fclose($pipe);
    }

    return proc_close($process) === 0;
}

/**
 * @return array{process: resource, stdin: resource, directory: string, ca_pem: string, port: int}
 */
function startCurlProfileHttpsTestServer(): array
{
    $directory = sys_get_temp_dir().'/orbit-curl-profile-'.bin2hex(random_bytes(4));

    if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        throw new RuntimeException("Unable to create test directory: {$directory}");
    }

    $caKey = "{$directory}/ca.key";
    $caPem = "{$directory}/ca.pem";
    $serverKey = "{$directory}/server.key";
    $serverCsr = "{$directory}/server.csr";
    $serverCert = "{$directory}/server.crt";
    $serverConfig = "{$directory}/server.cnf";

    file_put_contents($serverConfig, <<<'CONF'
[req]
distinguished_name = subject
req_extensions = extensions
prompt = no

[subject]
CN = localhost

[extensions]
subjectAltName = @alt_names

[alt_names]
DNS.1 = localhost
IP.1 = 127.0.0.1
CONF);

    runCurlProfileOpenSsl([
        'openssl',
        'req',
        '-x509',
        '-newkey',
        'rsa:2048',
        '-nodes',
        '-days',
        '1',
        '-subj',
        '/CN=Orbit Test Root CA',
        '-keyout',
        $caKey,
        '-out',
        $caPem,
    ], $directory);

    runCurlProfileOpenSsl([
        'openssl',
        'req',
        '-newkey',
        'rsa:2048',
        '-nodes',
        '-keyout',
        $serverKey,
        '-out',
        $serverCsr,
        '-config',
        $serverConfig,
    ], $directory);

    runCurlProfileOpenSsl([
        'openssl',
        'x509',
        '-req',
        '-in',
        $serverCsr,
        '-CA',
        $caPem,
        '-CAkey',
        $caKey,
        '-CAcreateserial',
        '-out',
        $serverCert,
        '-days',
        '1',
        '-extensions',
        'extensions',
        '-extfile',
        $serverConfig,
    ], $directory);

    $port = unusedCurlProfileTestPort();
    $process = proc_open(
        [
            'openssl',
            's_server',
            '-quiet',
            '-www',
            '-accept',
            (string) $port,
            '-cert',
            $serverCert,
            '-key',
            $serverKey,
        ],
        [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ],
        $pipes,
        $directory,
    );

    if (! is_resource($process)) {
        removeCurlProfileTestDirectory($directory);

        throw new RuntimeException('Unable to start HTTPS profile test server.');
    }

    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);

        if (is_resource($socket)) {
            fclose($socket);

            return [
                'process' => $process,
                'stdin' => $pipes[0],
                'directory' => $directory,
                'ca_pem' => $caPem,
                'port' => $port,
            ];
        }

        usleep(100_000);
    }

    stopCurlProfileHttpsTestServer([
        'process' => $process,
        'stdin' => $pipes[0],
        'directory' => $directory,
        'ca_pem' => $caPem,
        'port' => $port,
    ]);

    throw new RuntimeException('HTTPS profile test server did not become ready.');
}

/**
 * @param  list<string>  $command
 */
function runCurlProfileOpenSsl(array $command, string $directory): void
{
    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $directory,
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start OpenSSL.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        throw new RuntimeException("OpenSSL command failed: {$stderr}{$stdout}");
    }
}

function unusedCurlProfileTestPort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

    if (! is_resource($socket)) {
        throw new RuntimeException("Unable to allocate profile test port: {$error}");
    }

    $address = stream_socket_get_name($socket, false);

    fclose($socket);

    if (! is_string($address) || ! str_contains($address, ':')) {
        throw new RuntimeException('Unable to determine profile test port.');
    }

    return (int) substr(strrchr($address, ':'), 1);
}

/**
 * @param  array{process: resource, stdin: resource, directory: string, ca_pem: string, port: int}  $server
 */
function stopCurlProfileHttpsTestServer(array $server): void
{
    fclose($server['stdin']);
    proc_terminate($server['process']);
    proc_close($server['process']);
    removeCurlProfileTestDirectory($server['directory']);
}

function removeCurlProfileTestDirectory(string $directory): void
{
    foreach (glob($directory.'/*') ?: [] as $path) {
        @unlink($path);
    }

    @rmdir($directory);
}

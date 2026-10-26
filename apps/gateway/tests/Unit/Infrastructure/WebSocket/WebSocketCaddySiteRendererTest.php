<?php

declare(strict_types=1);

use App\Infrastructure\WebSocket\WebSocketCaddySiteRenderer;
use App\Infrastructure\WebSocket\WebSocketFootprint;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;

it('proxies public traffic and admits internal callbacks only from the Gateway peer over TLS', function (): void {
    $binary = new ExecutableFinder()->find('caddy');
    if ($binary === null || PHP_OS_FAMILY !== 'Linux') {
        $this->markTestSkipped('Requires Caddy and Linux loopback source addresses.');
    }

    $directory = sys_get_temp_dir().'/orbit-websocket-caddy-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $listener = $backendListener = $caddy = null;

    try {
        // Caddy serves sockets this test already holds (socket activation). A freed port could be taken by another
        // test's Caddy, which listens with SO_REUSEPORT, and connections queue until Caddy is ready.
        [$listener, $port] = websocket_caddy_test_listener();
        [$backendListener, $backendPort] = websocket_caddy_test_listener();
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $request = openssl_csr_new(['commonName' => 'reverb.orbit'], $key);
        $certificate = openssl_csr_sign($request, null, $key, 1);
        openssl_x509_export_to_file($certificate, $directory.'/reverb.pem');
        openssl_pkey_export_to_file($key, $directory.'/reverb.key');
        $site = new WebSocketCaddySiteRenderer()->render($backendPort, '127.0.0.1');
        $site = str_replace([
            'reverb.orbit {', WebSocketFootprint::CaddyBindPlaceholder,
            WebSocketFootprint::CertificateCurrentDirectory,
        ], [
            'https://reverb.orbit:'.$port.' {', 'fd/3', $directory,
        ], $site);
        file_put_contents($directory.'/Caddyfile', "{\n admin off\n auto_https off\n servers {\n  protocols h1 h2\n }\n}\n".$site."\nhttp://:{$backendPort} {\n bind fd/4\n respond proxied 200\n}\n");
        $caddy = proc_open(
            [$binary, 'run', '--config', $directory.'/Caddyfile', '--adapter', 'caddyfile'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $directory.'/caddy.log', 'a'], 2 => ['file', $directory.'/caddy.log', 'a'], 3 => $listener, 4 => $backendListener],
            $pipes,
            null,
            ['XDG_DATA_HOME' => $directory.'/data', 'XDG_CONFIG_HOME' => $directory.'/config'] + getenv(),
        );
        fclose($listener);
        fclose($backendListener);
        expect($caddy)->toBeResource();

        expect(websocket_caddy_test_request($port, '/apps/a/events', '127.0.0.2', $directory))->toBe([200, 'proxied']);
        expect(websocket_caddy_test_request($port, '/internal/orbit/registry-changed', '127.0.0.1', $directory))->toBe([200, 'proxied']);
        foreach (['/internal/orbit/registry-changed', '/%69nternal/orbit/registry-changed'] as $path) {
            expect(websocket_caddy_test_request($port, $path, '127.0.0.2', $directory))->toBe([403, '']);
        }
    } finally {
        foreach ([$listener, $backendListener] as $socket) {
            if (is_resource($socket)) {
                fclose($socket);
            }
        }
        if (is_resource($caddy)) {
            proc_terminate($caddy, SIGKILL);
            proc_close($caddy);
        }
        new Filesystem()->remove($directory);
    }
});

/** @return array{resource, int} */
function websocket_caddy_test_listener(): array
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');

    return [$socket, (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1)];
}

/** @return array{int, string} */
function websocket_caddy_test_request(int $port, string $path, string $source, string $directory): array
{
    $curl = curl_init('https://reverb.orbit:'.$port.$path);
    curl_setopt_array($curl, [
        CURLOPT_RESOLVE => ['reverb.orbit:'.$port.':127.0.0.1'],
        CURLOPT_PROXY => '',
        CURLOPT_INTERFACE => $source,
        CURLOPT_CAINFO => $directory.'/reverb.pem',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => '{}',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Forwarded-For: 127.0.0.1'],
    ]);
    $body = curl_exec($curl);
    // The kernel accepts connections even when Caddy fails to start, so a failure shows Caddy's log.
    expect($body)->not->toBeFalse(curl_error($curl)."\nCaddy:\n".file_get_contents($directory.'/caddy.log'));

    return [(int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $body];
}

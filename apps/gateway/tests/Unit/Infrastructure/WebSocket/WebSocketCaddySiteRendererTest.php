<?php

declare(strict_types=1);

use App\Infrastructure\WebSocket\WebSocketCaddySiteRenderer;
use App\Infrastructure\WebSocket\WebSocketFootprint;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

it('proxies public traffic and admits internal callbacks only from the Gateway peer over TLS', function (): void {
    $binary = new ExecutableFinder()->find('caddy');
    if ($binary === null || PHP_OS_FAMILY !== 'Linux') {
        $this->markTestSkipped('Requires Caddy and Linux loopback source addresses.');
    }

    $directory = sys_get_temp_dir().'/orbit-websocket-caddy-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $port = websocket_caddy_test_port();
    $backendPort = websocket_caddy_test_port();
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
        'https://reverb.orbit:'.$port.' {', '127.0.0.1', $directory,
    ], $site);
    file_put_contents($directory.'/Caddyfile', "{\n admin off\n auto_https off\n}\n".$site."\nhttp://:{$backendPort} {\n bind 127.0.0.1\n respond proxied 200\n}\n");
    $process = new Process([$binary, 'run', '--config', $directory.'/Caddyfile', '--adapter', 'caddyfile'], env: [
        'XDG_DATA_HOME' => $directory.'/data', 'XDG_CONFIG_HOME' => $directory.'/config',
    ]);

    try {
        $process->start();
        expect($process->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'serving initial configuration')))
            ->toBeTrue($process->getErrorOutput());

        expect(websocket_caddy_test_request($port, '/apps/a/events', '127.0.0.2', $directory))->toBe([200, 'proxied']);
        expect(websocket_caddy_test_request($port, '/internal/orbit/registry-changed', '127.0.0.1', $directory))->toBe([200, 'proxied']);
        foreach (['/internal/orbit/registry-changed', '/%69nternal/orbit/registry-changed'] as $path) {
            expect(websocket_caddy_test_request($port, $path, '127.0.0.2', $directory))->toBe([403, '']);
        }
    } finally {
        $process->stop();
        new Filesystem()->remove($directory);
    }
});

function websocket_caddy_test_port(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);

    return $port;
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
    expect($body)->not->toBeFalse(curl_error($curl));

    return [(int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $body];
}

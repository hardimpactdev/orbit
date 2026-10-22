<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use Illuminate\Support\Facades\File;
use Orbit\Extensions\Documents\Infrastructure\ProbeResponseBuffer;

require_once __DIR__.'/../../../../Support/ProbeTransportFixtures.php';

pest()->group('subprocess');

beforeEach(function (): void {
    $home = sys_get_temp_dir().'/orbit-probe-deadline-'.bin2hex(random_bytes(16));
    mkdir($home, 0o700, true);
    config(['orbit.home' => $home]);
    $this->app->useStoragePath($home.'/storage');
    $this->beforeApplicationDestroyed(static function () use ($home): void {
        File::deleteDirectory($home);
    });
});

it('enforces the connection bound during a stalled TLS handshake', function (): void {
    [$server, $client] = probe_transport_fixture('tls-stall');
    $command = $client->getCommand('GetObject', [
        'Bucket' => 'fixture-bucket', 'Key' => 'orbit-document-probes/fixture',
        '@http' => ['stream' => false, 'sink' => new ProbeResponseBuffer],
    ]);
    expect($command['@http']['connect_timeout'])->toBe(2);

    try {
        expect(fn () => $client->execute($command))->toThrow(function (RuntimeException $exception): void {
            expect($exception->getPrevious())->toBeInstanceOf(ConnectTimeoutException::class);
        });
    } finally {
        $server->stop();
    }
});

it('enforces the total request deadline when a response body stalls', function (): void {
    [$server, $client] = probe_transport_fixture('body-stall');
    $command = $client->getCommand('GetObject', [
        'Bucket' => 'fixture-bucket', 'Key' => 'orbit-document-probes/fixture',
        '@http' => ['stream' => false, 'sink' => new ProbeResponseBuffer],
    ]);
    expect($command['@http']['timeout'])->toBe(5);

    try {
        expect(fn () => $client->execute($command))->toThrow(function (RuntimeException $exception): void {
            $previous = $exception->getPrevious();
            expect($previous)->toBeInstanceOf(ResponseTimeoutException::class);
            expect($previous->getResponse()->getStatusCode())->toBe(200);
            expect((string) $previous->getResponse()->getBody())->toBe('aaaaaaaa');
        });
        expect(array_column(probe_transport_state()['requests'], 'method'))->toBe(['GET']);
    } finally {
        $server->stop();
    }
});

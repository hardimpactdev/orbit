<?php

declare(strict_types=1);

namespace Tests\Feature\Extensions\Documents\Infrastructure;

use App\Domain\Extensions\ExtensionStore;
use GuzzleHttp\Exception\ConnectTimeoutException;
use GuzzleHttp\Exception\ResponseTimeoutException;
use Illuminate\Support\Facades\File;
use Orbit\Extensions\Documents\Infrastructure\ProbeResponseBuffer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Large;
use RuntimeException;
use Tests\FeatureTestCase;

require_once __DIR__.'/../../../../Support/ProbeTransportFixtures.php';

#[Group('subprocess')]
#[Large] // These two cases prove native Guzzle's real 2-second TLS and 5-second request deadlines.
final class DocumentTransportDeadlineTest extends FeatureTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        app(ExtensionStore::class)->set('documents', true);
        $home = sys_get_temp_dir().'/orbit-probe-deadline-'.bin2hex(random_bytes(16));
        mkdir($home, 0o700, true);
        config(['orbit.home' => $home]);
        $this->app->useStoragePath($home.'/storage');
        $this->beforeApplicationDestroyed(static function () use ($home): void {
            File::deleteDirectory($home);
        });
    }

    public function test_enforces_the_connection_bound_during_a_stalled_tls_handshake(): void
    {
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
    }

    public function test_enforces_the_total_request_deadline_when_a_response_body_stalls(): void
    {
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
    }
}

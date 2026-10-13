<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RealtimeConnection;
use App\Domain\Broadcasting\RealtimeConnectionData;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\Schedules\ScheduleRenderer;
use App\Domain\Schedules\ScheduleTarget;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Infrastructure\Certificates\OrbitCaCurlFactory;
use App\Infrastructure\GatewayReleases\HttpGatewayReleaseVerifier;
use App\Infrastructure\ProxyCli\HttpProxyCliAccountControlClient;
use App\Models\Node;
use App\Models\Schedule;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

pest()->group('subprocess');

require_once __DIR__.'/../../../Support/OrbitHttps.php';

beforeEach(function (): void {
    Http::allowStrayRequests();
    $this->servers = [];
    $this->directory = sys_get_temp_dir().'/orbit-https-'.Str::random(8);
    mkdir($this->directory);
    mkdir($this->directory.'/ca');
    $this->orbitRoot = orbit_https_ca($this->directory, 'orbit');
    $this->systemRoot = orbit_https_ca($this->directory, 'system');
    copy($this->orbitRoot, $this->directory.'/ca/root.pem');
    config(['orbit.home' => $this->directory]);
    $this->signer = new class($this->orbitRoot) implements LeafCertificateSigner
    {
        public function __construct(private string $path) {}

        public function sign(string $hostname, string $certificateRequest): string
        {
            throw new LogicException('Signing is not part of an HTTP request.');
        }

        public function rootCertificate(): string
        {
            return (string) file_get_contents($this->path);
        }
    };
});

afterEach(function (): void {
    foreach ($this->servers as $process) {
        proc_terminate($process);
        proc_close($process);
    }

    File::deleteDirectory($this->directory);
});

it('disables the compiled-in cURL CA directory while retaining the Orbit CA file', function (): void {
    $port = orbit_https_server($this->servers, $this->directory, 'orbit', 'ok');
    $trace = fopen('php://temp', 'w+');
    $factory = new OrbitCaCurlFactory;
    $handler = new CurlHandler(['handle_factory' => $factory]);

    try {
        $response = Http::setHandler($handler)
            ->withOptions(['verify' => $this->orbitRoot, 'debug' => $trace])
            ->get("https://127.0.0.1:{$port}/");
        rewind($trace);
        $debug = stream_get_contents($trace);

        expect($response->status())->toBe(200);
        expect($debug)->toContain('CAfile: '.$this->orbitRoot);
        expect(preg_match('/CApath: (.+)/', $debug, $matches))->toBe(1);
        $directory = trim($matches[1]);
        expect(scandir($directory))->toBe(['.', '..']);
        expect(fileperms($directory) & 0o777)->toBe(0o700);
    } finally {
        fclose($trace);
    }

    unset($handler, $factory);
    expect(is_dir($directory))->toBeFalse();
});

it('uses the checked state CA in place for proxycli account updates', function (): void {
    $verify = null;
    Http::fake(function ($request, array $options) use (&$verify) {
        $verify = $options['verify'];

        return Http::response([], 200);
    });

    (new HttpProxyCliAccountControlClient($this->signer))->setDisabled('127.0.0.1', 443, 'control-token', 'account', true);

    expect($verify)->toBe($this->directory.'/ca/root.pem');
    Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
        && $request->hasHeader('Host', 'collector.cli-proxy-api.orbit')
        && $request->hasHeader('Authorization', 'Bearer control-token')
        && $request['disabled'] === true);
});

it('pins the Reverb transport without losing address resolution or old-server limits', function (bool $oldServer): void {
    $connection = new RealtimeConnectionData('reverb.orbit', 443, 'https', 'app', 'key', 'secret', $this->orbitRoot, '127.0.0.1');
    app(RealtimeConnection::class)->configureBroadcasting($connection, $oldServer);
    $options = config('broadcasting.connections.reverb.client_options');

    expect($options['handler'] ?? null)->toBeInstanceOf(CurlHandler::class);
    expect($options['curl'])->toBe([CURLOPT_RESOLVE => ['reverb.orbit:443:127.0.0.1']]);
    expect($options['timeout'])->toBe($oldServer ? 0.5 : 5);
    expect($options['connect_timeout'])->toBe($oldServer ? 0.3 : 2);
})->with([false, true]);

it('reuses one private CA directory across separate realtime connections in a process', function (): void {
    $port = orbit_https_server($this->servers, $this->directory, 'orbit', 'ok');
    $connection = new RealtimeConnectionData('reverb.orbit', $port, 'https', 'app', 'key', 'secret', $this->orbitRoot, '127.0.0.1');
    $trace = fopen('php://temp', 'w+');

    try {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $realtime = new RealtimeConnection(app(WebSocketCredentialManager::class), $this->directory);
            $realtime->configureBroadcasting($connection);
            config(['broadcasting.connections.reverb.client_options.debug' => $trace]);
            Broadcast::purge('reverb');

            expect(Broadcast::connection('reverb')->getPusher()->trigger('private-orbit', 'test', ['value' => 'ok']))->toBeObject();
        }

        rewind($trace);
        expect(preg_match_all('/CApath: (.+)/', stream_get_contents($trace), $matches))->toBe(2);
        $directories = array_map(trim(...), $matches[1]);
        expect(array_unique($directories))->toHaveCount(1);
        expect(scandir($directories[0]))->toBe(['.', '..']);
        expect(fileperms($directories[0]) & 0o777)->toBe(0o700);
    } finally {
        Broadcast::purge('reverb');
        config(['broadcasting.connections.reverb.client_options.debug' => null]);
        fclose($trace);
    }
});

it('accepts Orbit certificates and rejects a different CA for internal HTTPS', function (string $client): void {
    $trusted = orbit_https_server($this->servers, $this->directory, 'orbit', 'ok');
    $untrusted = orbit_https_server($this->servers, $this->directory, 'system', 'ok');

    // Control: the other CA can serve HTTPS when explicitly trusted.
    expect(Http::withOptions(['verify' => $this->systemRoot])->get("https://127.0.0.1:{$untrusted}")->status())->toBe(200);

    $send = function (int $port) use ($client): void {
        if ($client === 'proxycli') {
            (new HttpProxyCliAccountControlClient($this->signer))->setDisabled('127.0.0.1', $port, 'control-token', 'account', true);
        } elseif ($client === 'reverb') {
            $connection = new RealtimeConnectionData('reverb.orbit', $port, 'https', 'app', 'key', 'secret', $this->orbitRoot, '127.0.0.1');
            app(RealtimeConnection::class)->configureBroadcasting($connection);
            Broadcast::purge('reverb');
            expect(Broadcast::connection('reverb')->getPusher()->trigger('private-orbit', 'test', ['value' => 'ok']))->toBeObject();
        } else {
            $verifier = new HttpGatewayReleaseVerifier(origin: "https://127.0.0.1:{$port}", caFile: $this->orbitRoot, backoff: []);
            expect($verifier->verify(str_repeat('a', 40))['status'])->toBe('ok');
        }
    };

    $send($trusted);
    expect(fn () => $send($untrusted))->toThrow(Exception::class);
})->with(['proxycli', 'reverb', 'release']);

it('sends Schedule callbacks only to an Orbit certificate and cleans up the private CA directory', function (): void {
    $trusted = orbit_https_server($this->servers, $this->directory, 'orbit', 'ok');
    $untrusted = orbit_https_server($this->servers, $this->directory, 'system', 'ok');
    $systemStore = $this->directory.'/system-store';
    $temporary = $this->directory.'/callback-tmp';
    mkdir($systemStore);
    mkdir($temporary);
    $hash = openssl_x509_parse((string) file_get_contents($this->systemRoot))['hash'];
    symlink($this->systemRoot, $systemStore.'/'.$hash.'.0');
    $schedule = new Schedule(['id' => '123e4567-e89b-42d3-a456-426614174000', 'command' => 'true']);
    $target = new ScheduleTarget(new Node, 'orbit', 'orbit', '/home/orbit', '/home/orbit', '/bin/bash', false, null);

    foreach ([$trusted, $untrusted] as $port) {
        $renderer = new ScheduleRenderer($this->signer, "https://127.0.0.1:{$port}");
        // Supply the ambient CA directory explicitly, as libcurl's compiled-in directory would.
        // The rendered callback must override it with an empty private directory.
        $script = str_replace('/usr/bin/curl ', '/usr/bin/curl --capath '.escapeshellarg($systemStore).' ', $renderer->renderScript($schedule, $target));
        (new Process(['/bin/bash', '-c', $script, 'callback', 'complete'], env: ['TMPDIR' => $temporary, 'SERVICE_RESULT' => 'success']))->mustRun();
    }

    expect(is_file($this->directory.'/received-orbit.pem'))->toBeTrue();
    expect(is_file($this->directory.'/received-system.pem'))->toBeFalse();
    expect(glob($temporary.'/*'))->toBe([]);
});

it('fails release verification when the Orbit CA file is missing', function (): void {
    $port = orbit_https_server($this->servers, $this->directory, 'orbit', 'ok');
    $verifier = new HttpGatewayReleaseVerifier(origin: "https://127.0.0.1:{$port}", caFile: $this->directory.'/missing.pem', backoff: []);

    expect(fn () => $verifier->serving())->toThrow(function (GatewayReleaseException $exception): void {
        expect($exception->step)->toBe('verify');
        expect($exception->errorCode)->toBe('gateway.release_verify_failed');
        expect($exception->getMessage())->toBe('Gateway /up did not succeed. Internal HTTPS requires an Orbit CA file.');
    });
});

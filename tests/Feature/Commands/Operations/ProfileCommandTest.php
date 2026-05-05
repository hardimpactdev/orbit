<?php

declare(strict_types=1);

use App\Contracts\RequestProfiler;
use App\Models\App;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('profiles a gateway-local app target as baseline json', function (): void {
    $gateway = Node::factory()->create([
        'name' => 'gateway',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $gateway->id,
        'domain' => 'docs.test',
        'path' => '/srv/docs',
    ]);

    app()->instance(RequestProfiler::class, new class implements RequestProfiler
    {
        public array $calls = [];

        public function profile(string $url, array $headers = []): array
        {
            $this->calls[] = compact('url', 'headers');

            return [
                'request' => [
                    'method' => 'GET',
                    'url' => $url,
                    'uri' => '/',
                    'status' => 200,
                    'bytes' => 1200,
                    'completed' => true,
                ],
                'timings' => [
                    'dns_ms' => 1.0,
                    'connect_ms' => 3.0,
                    'tls_ms' => 7.0,
                    'ttfb_ms' => 50.0,
                    'download_ms' => 2.0,
                    'total_ms' => 52.0,
                ],
                'error' => null,
                'response_headers' => [
                    'content-type' => 'text/html',
                ],
            ];
        }
    });

    $exitCode = Artisan::call('profile', [
        'target' => 'docs',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['source'])->toBe('baseline')
        ->and($payload['success']['data']['instrumented'])->toBeFalse()
        ->and($payload['success']['data']['auth_mode'])->toBe('guest')
        ->and($payload['success']['data']['origin'])->toBe('gateway')
        ->and($payload['success']['data']['target'])->toBe([
            'app' => 'docs',
            'workspace' => null,
            'node' => 'gateway',
            'domain' => 'docs.test',
        ])
        ->and($payload['success']['data']['request']['url'])->toBe('https://docs.test/')
        ->and($payload['success']['data']['request']['status'])->toBe(200)
        ->and($payload['success']['meta']['warnings'])->toBe([]);
});

it('treats a completed non-2xx response as a successful profile result', function (): void {
    $gateway = Node::factory()->create([
        'name' => 'gateway',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $gateway->id,
        'domain' => 'docs.test',
    ]);

    app()->instance(RequestProfiler::class, new class implements RequestProfiler
    {
        public function profile(string $url, array $headers = []): array
        {
            return [
                'request' => [
                    'method' => 'GET',
                    'url' => $url,
                    'uri' => '/missing',
                    'status' => 404,
                    'bytes' => 100,
                    'completed' => true,
                ],
                'timings' => [
                    'dns_ms' => 0.0,
                    'connect_ms' => 0.0,
                    'tls_ms' => 0.0,
                    'ttfb_ms' => 5.0,
                    'download_ms' => 1.0,
                    'total_ms' => 6.0,
                ],
                'error' => null,
                'response_headers' => [],
            ];
        }
    });

    $exitCode = Artisan::call('profile', [
        '--app' => 'docs',
        '--uri' => '/missing',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['request']['status'])->toBe(404)
        ->and($payload['success']['data']['request']['completed'])->toBeTrue();
});

it('fails validation when target and app are combined', function (): void {
    $exitCode = Artisan::call('profile', [
        'target' => 'docs',
        '--app' => 'docs',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta'])->toBe([
            'field' => 'target',
            'reason' => 'conflicts_with_app',
        ]);
});

<?php

declare(strict_types=1);

use App\Contracts\RequestProfiler;
use App\Models\App;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders baseline profile timing in human mode', function (): void {
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
                'response_headers' => [],
            ];
        }
    });

    $this->artisan('profile docs')
        ->expectsOutputToContain('┌  Profiling /')
        ->expectsOutputToContain('●  Resolved target')
        ->expectsOutputToContain('●  Collected timing data')
        ->expectsOutputToContain('Profiled https://docs.test/ in 52.00ms')
        ->expectsOutputToContain('GET https://docs.test/ 200 in 52.00ms')
        ->expectsOutputToContain('DNS 1.00ms')
        ->expectsOutputToContain('Total 52.00ms')
        ->assertSuccessful();
});

it('renders toolbar stages and query summary in human mode', function (): void {
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

    $toolbar = [
        'timing_anchors' => [
            'caddy_start_ms' => 0.0,
            'php_start_ms' => 1.5,
            'laravel_start_ms' => 12.0,
            'profiler_end_ms' => 105.0,
            'collected_at_ms' => 108.0,
        ],
        'profiler' => [
            'stages' => [
                ['label' => 'Middleware', 'duration_ms' => 10.5],
                ['label' => 'Controller', 'duration_ms' => 80.2],
                ['label' => 'View', 'duration_ms' => 2.3],
            ],
        ],
        'queries' => [
            'count' => 5,
            'slow_count' => 1,
            'duplicate_count' => 2,
        ],
    ];

    app()->instance(RequestProfiler::class, new class($toolbar) implements RequestProfiler
    {
        public function __construct(
            private readonly array $toolbar,
        ) {}

        public function profile(string $url, array $headers = []): array
        {
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
                    'ttfb_ms' => 115.0,
                    'download_ms' => 2.0,
                    'total_ms' => 117.0,
                ],
                'error' => null,
                'response_headers' => [
                    'x-toolbar-summary' => base64_encode(json_encode($this->toolbar, JSON_THROW_ON_ERROR)),
                ],
            ];
        }
    });

    $this->artisan('profile docs')
        ->expectsOutputToContain('Middleware 10.50ms')
        ->expectsOutputToContain('Controller 80.20ms')
        ->expectsOutputToContain('View 2.30ms')
        ->expectsOutputToContain('Toolbar 3.00ms')
        ->expectsOutputToContain('5 queries, 1 slow, 2 duplicate')
        ->assertSuccessful();
});

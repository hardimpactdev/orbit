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
        ->expectsOutputToContain('┌ Profiling /')
        ->expectsOutputToContain('Profiled https://docs.test/ in 52.00ms')
        ->expectsOutputToContain('GET https://docs.test/ 200 in 52.00ms')
        ->expectsOutputToContain('DNS 1.00ms')
        ->expectsOutputToContain('Total 52.00ms')
        ->assertSuccessful();
});

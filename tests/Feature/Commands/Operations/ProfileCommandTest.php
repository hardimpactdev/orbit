<?php

declare(strict_types=1);

use App\Contracts\RequestProfiler;
use App\Http\Gateway\Requests\Apps\ShowAppRequest;
use App\Http\Gateway\Requests\Profile\ShowProfileRequest;
use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

afterEach(function (): void {
    MockClient::destroyGlobal();
});

it('profiles an app target resolved from gateway state as baseline json', function (): void {
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

it('infers an app target from the gateway caller current working directory', function (): void {
    $gateway = Node::factory()->create([
        'name' => 'gateway',
        'role' => 'gateway',
        'is_local' => true,
    ]);

    $appPath = sys_get_temp_dir().'/orbit-profile-cwd-'.bin2hex(random_bytes(4));
    mkdir($appPath.'/subdir', 0777, true);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $gateway->id,
        'domain' => 'docs.test',
        'path' => $appPath,
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

    $originalCwd = getcwd();

    try {
        chdir($appPath.'/subdir');

        $exitCode = Artisan::call('profile', [
            '--json' => true,
        ]);
    } finally {
        if (is_string($originalCwd)) {
            chdir($originalCwd);
        }
    }

    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['target']['app'])->toBe('docs')
        ->and($payload['success']['data']['request']['url'])->toBe('https://docs.test/');
});

it('resolves a control caller target through the gateway before profiling', function (): void {
    Node::factory()->create([
        'name' => 'control-1',
        'role' => 'control',
        'is_local' => true,
    ]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();

    MockClient::global([
        ShowAppRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'app' => [
                        'name' => 'docs',
                        'node' => 'app-dev-1',
                        'url' => 'https://docs.test',
                    ],
                    'details' => [],
                ],
            ],
        ], 200),
    ]);

    app()->instance(RequestProfiler::class, new class implements RequestProfiler
    {
        public function profile(string $url, array $headers = []): array
        {
            return [
                'request' => [
                    'method' => 'GET',
                    'url' => $url,
                    'uri' => '/login',
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

    $exitCode = Artisan::call('profile', [
        'target' => 'docs',
        '--uri' => '/login',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['origin'])->toBe('caller')
        ->and($payload['success']['data']['target'])->toBe([
            'app' => 'docs',
            'workspace' => null,
            'node' => 'app-dev-1',
            'domain' => 'docs.test',
        ])
        ->and($payload['success']['data']['request']['url'])->toBe('https://docs.test/login');
});

it('asks the gateway to profile targets for app callers', function (): void {
    Node::factory()->create([
        'name' => 'app-local',
        'role' => 'app',
        'is_local' => true,
    ]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();

    MockClient::global([
        ShowProfileRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'source' => 'baseline',
                    'instrumented' => false,
                    'auth_mode' => 'first-user',
                    'request_id' => 'profile-request-id',
                    'origin' => 'gateway',
                    'target' => [
                        'app' => 'docs',
                        'workspace' => null,
                        'node' => 'app-dev-1',
                        'domain' => 'docs.test',
                    ],
                    'request' => [
                        'method' => 'GET',
                        'url' => 'https://docs.test/login',
                        'uri' => '/login',
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
                ],
            ],
        ], 200),
    ]);

    app()->instance(RequestProfiler::class, new class implements RequestProfiler
    {
        public function profile(string $url, array $headers = []): array
        {
            throw new RuntimeException('App callers must not profile from the caller process.');
        }
    });

    $exitCode = Artisan::call('profile', [
        'target' => 'docs',
        '--uri' => '/login',
        '--as-first-user' => true,
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['origin'])->toBe('gateway')
        ->and($payload['success']['data']['target']['app'])->toBe('docs')
        ->and($payload['success']['data']['request']['url'])->toBe('https://docs.test/login');
});

it('falls back to gateway-origin profiling for control callers when caller-origin profiling fails', function (): void {
    Node::factory()->create([
        'name' => 'control-1',
        'role' => 'control',
        'is_local' => true,
    ]);

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://10.6.0.1',
        'ca_pem_path' => '/dev/null',
    ])->save();

    MockClient::global([
        ShowAppRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'app' => [
                        'name' => 'docs',
                        'node' => 'app-dev-1',
                        'url' => 'https://docs.test',
                    ],
                    'details' => [],
                ],
            ],
        ], 200),
        ShowProfileRequest::class => MockResponse::make([
            'success' => [
                'data' => [
                    'source' => 'baseline',
                    'instrumented' => false,
                    'auth_mode' => 'guest',
                    'request_id' => 'gateway-profile-request-id',
                    'origin' => 'gateway',
                    'target' => [
                        'app' => 'docs',
                        'workspace' => null,
                        'node' => 'app-dev-1',
                        'domain' => 'docs.test',
                    ],
                    'request' => [
                        'method' => 'GET',
                        'url' => 'https://docs.test/',
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
                ],
            ],
        ], 200),
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
                    'status' => null,
                    'bytes' => 0,
                    'completed' => false,
                ],
                'timings' => [
                    'dns_ms' => 0.0,
                    'connect_ms' => 0.0,
                    'tls_ms' => 0.0,
                    'ttfb_ms' => 0.0,
                    'download_ms' => 0.0,
                    'total_ms' => 3000.0,
                ],
                'error' => ['message' => 'Could not resolve host'],
                'response_headers' => [],
            ];
        }
    });

    $exitCode = Artisan::call('profile', [
        'target' => 'docs',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($payload['success']['data']['origin'])->toBe('gateway')
        ->and($payload['success']['data']['request']['url'])->toBe('https://docs.test/');
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

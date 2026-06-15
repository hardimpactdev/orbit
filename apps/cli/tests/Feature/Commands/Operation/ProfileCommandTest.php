<?php

declare(strict_types=1);

use App\Services\Profile\ProfileRequestProfiler;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('profile', function (): void {
    it('keeps caller-side TLS verification enabled and redirect following disabled', function (): void {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Profile/CurlProfileRequestProfiler.php');

        expect($source)
            ->toContain('CURLOPT_FOLLOWLOCATION => false')
            ->toContain('CURLOPT_SSL_VERIFYPEER => true')
            ->toContain('CURLOPT_SSL_VERIFYHOST => 2');
    });

    it('resolves the target through the gateway and profiles the HTTP request from the caller', function (): void {
        $toolbar = [
            'queries' => [
                'count' => 5,
                'slow_count' => 1,
                'duplicate_count' => 0,
            ],
        ];
        $server = startProfileCommandTestServer($toolbar);

        try {
            fakeGateway(fakeSuccessEnvelope(fakeProfileResolutionData([
                'auth_mode' => 'user',
                'request' => [
                    'method' => 'GET',
                    'url' => "http://127.0.0.1:{$server['port']}/login?filter=active",
                    'uri' => '/login?filter=active',
                ],
            ]), ['warnings' => []]));

            [$exitCode, $output] = runCommand($this, 'profile', [
                'target' => 'docs',
                '--uri' => '/login?filter=active',
                '--user' => '42',
                '--node' => 'app-1',
                '--json' => true,
            ]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
            $captured = json_decode(file_get_contents($server['capture']), associative: true, flags: JSON_THROW_ON_ERROR);

            Http::assertSent(function (Request $request): bool {
                $query = profileRequestQuery($request);

                return $request->method() === 'GET'
                    && profileRequestPath($request) === '/api/profile/resolve'
                    && $query['target'] === 'docs'
                    && $query['uri'] === '/login?filter=active'
                    && $query['auth_mode'] === 'user'
                    && $query['user'] === '42'
                    && $query['node'] === 'app-1';
            });
            Http::assertNotSent(fn (Request $request): bool => profileRequestPath($request) === '/api/profile');

            expect($exitCode)->toBe(0)
                ->and($decoded['success']['data']['origin'])->toBe('caller')
                ->and($decoded['success']['data']['source'])->toBe('baseline+toolbar')
                ->and($decoded['success']['data']['instrumented'])->toBeTrue()
                ->and($decoded['success']['data']['auth_mode'])->toBe('user')
                ->and($decoded['success']['data']['target']['app'])->toBe('docs')
                ->and($decoded['success']['data']['request']['status'])->toBe(200)
                ->and($decoded['success']['data']['request']['completed'])->toBeTrue()
                ->and($decoded['success']['data']['toolbar']['queries']['count'])->toBe(5)
                ->and($captured['uri'])->toBe('/login?filter=active')
                ->and($captured['auth'])->toBe('user')
                ->and($captured['user'])->toBe('42')
                ->and($captured['request_id'])->toBe($decoded['success']['data']['request_id']);
        } finally {
            stopProfileCommandTestServer($server);
        }
    });

    it('reports redirects without following them away from the resolved app route', function (): void {
        $server = startProfileCommandTestServer(redirectLocation: '/final');

        try {
            fakeGateway(fakeSuccessEnvelope(fakeProfileResolutionData([
                'request' => [
                    'method' => 'GET',
                    'url' => "http://127.0.0.1:{$server['port']}/redirect",
                    'uri' => '/redirect',
                ],
            ]), ['warnings' => []]));

            [$exitCode, $output] = runCommand($this, 'profile', [
                'target' => 'docs',
                '--json' => true,
            ]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
            $captured = json_decode(file_get_contents($server['capture']), associative: true, flags: JSON_THROW_ON_ERROR);

            expect($exitCode)->toBe(0)
                ->and($decoded['success']['data']['request']['status'])->toBe(302)
                ->and($decoded['success']['data']['request'])->not->toHaveKey('effective_url')
                ->and($captured['uri'])->toBe('/redirect');
        } finally {
            stopProfileCommandTestServer($server);
        }
    });

    it('fails with caller-origin diagnostics when the local profile request cannot connect', function (): void {
        $port = unusedProfileCommandTestPort();

        fakeGateway(fakeSuccessEnvelope(fakeProfileResolutionData([
            'request' => [
                'method' => 'GET',
                'url' => "http://127.0.0.1:{$port}/",
                'uri' => '/',
            ],
        ]), ['warnings' => []]));

        [$exitCode, $output] = runCommand($this, 'profile', [
            'target' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => profileRequestPath($request) === '/api/profile/resolve');
        Http::assertNotSent(fn (Request $request): bool => profileRequestPath($request) === '/api/profile');

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('profile_request_failed')
            ->and($decoded['error']['data']['request']['completed'])->toBeFalse()
            ->and($decoded['error']['meta']['origin'])->toBe('caller')
            ->and($decoded['error']['meta']['url'])->toBe("http://127.0.0.1:{$port}/");
    });

    it('returns profile data as a canonical JSON envelope and forwards profile options', function (): void {
        $profiler = fakeLocalProfile(fakeProfileData());
        fakeGateway(fakeSuccessEnvelope(fakeProfileResolutionData(['auth_mode' => 'user']), ['warnings' => []]));

        [$exitCode, $output] = runCommand($this, 'profile', [
            'target' => 'docs',
            '--uri' => 'login',
            '--user' => '42',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $query = profileRequestQuery($request);

            return $request->method() === 'GET'
                && profileRequestPath($request) === '/api/profile/resolve'
                && $query['target'] === 'docs'
                && $query['uri'] === '/login'
                && $query['auth_mode'] === 'user'
                && $query['user'] === '42'
                && $query['node'] === 'app-1';
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['request']['url'])->toBe('https://docs.test/')
            ->and($decoded['success']['data']['origin'])->toBe('caller')
            ->and($decoded['success']['data']['auth_mode'])->toBe('user')
            ->and($decoded['success']['meta']['warnings'])->toBe([])
            ->and($profiler->calls[0]['headers']['X-TOOLBAR-AUTH'])->toBe('user')
            ->and($profiler->calls[0]['headers']['X-TOOLBAR-USER'])->toBe('42');
    });

    it('splits full URL targets into gateway target and request URI', function (): void {
        fakeLocalProfile(fakeProfileData());
        fakeGateway(fakeSuccessEnvelope(fakeProfileResolutionData()));

        [$exitCode, $output] = runCommand($this, 'profile', [
            'target' => 'https://docs.test/admin/users?filter=active',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $query = profileRequestQuery($request);

            return $query['target'] === 'docs.test'
                && $query['uri'] === '/admin/users?filter=active'
                && $query['auth_mode'] === 'guest'
                && ! array_key_exists('user', $query);
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['target']['app'])->toBe('docs');
    });

    it('uses --app as the gateway target and supports first-user auth', function (): void {
        $profiler = fakeLocalProfile(fakeProfileData());
        fakeGateway(fakeSuccessEnvelope(fakeProfileResolutionData(['auth_mode' => 'first-user'])));

        [$exitCode] = runCommand($this, 'profile', [
            '--app' => 'docs',
            '--as-first-user' => true,
            '--json' => true,
        ]);

        Http::assertSent(function (Request $request): bool {
            $query = profileRequestQuery($request);

            return $query['target'] === 'docs'
                && $query['uri'] === '/'
                && $query['auth_mode'] === 'first-user';
        });

        expect($exitCode)->toBe(0)
            ->and($profiler->calls[0]['headers']['X-TOOLBAR-AUTH'])->toBe('first-user');
    });

    it('forwards the host current working directory when target is omitted', function (): void {
        $previousHostCwd = getenv('ORBIT_HOST_CWD');

        try {
            putenv('ORBIT_HOST_CWD=/home/nick/sites/docs/current');
            fakeLocalProfile(fakeProfileData());
            fakeGateway(fakeSuccessEnvelope(fakeProfileResolutionData()));

            [$exitCode] = runCommand($this, 'profile', ['--json' => true]);

            Http::assertSent(function (Request $request): bool {
                $query = profileRequestQuery($request);

                return $query['target'] === '/home/nick/sites/docs/current'
                    && $query['uri'] === '/';
            });

            expect($exitCode)->toBe(0);
        } finally {
            restoreHostCwd($previousHostCwd);
        }
    });

    it('fails validation before gateway IO when target and app are combined', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'profile', [
            'target' => 'docs',
            '--app' => 'docs-api',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['message'])->toBe('Use either target or --app, not both.')
            ->and($decoded['error']['meta']['reason'])->toBe('conflicts_with_app');
    });

    it('fails validation before gateway IO when auth modes are combined', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'profile', [
            'target' => 'docs',
            '--as-first-user' => true,
            '--user' => '42',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['message'])->toBe('Use either --as-first-user or --user, not both.')
            ->and($decoded['error']['meta']['reason'])->toBe('conflicting_auth_modes');
    });

    it('renders baseline and toolbar timing in human mode', function (): void {
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
                ],
            ],
            'queries' => [
                'count' => 5,
                'slow_count' => 1,
                'duplicate_count' => 2,
            ],
        ];

        fakeLocalProfile(fakeProfileData([
            'response_headers' => [
                'x-caddy-end' => 109.2,
                'x-toolbar-summary' => base64_encode(json_encode($toolbar, JSON_THROW_ON_ERROR)),
            ],
        ]));
        fakeGateway(fakeSuccessEnvelope(fakeProfileResolutionData()));

        [$exitCode, $output] = runCommand($this, 'profile', ['target' => 'docs']);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('GET https://docs.test/ 200 in 115.42ms')
            ->and($output)->toContain('DNS')
            ->and($output)->toContain('Connect')
            ->and($output)->toContain('Waiting for response')
            ->and($output)->toContain('Middleware')
            ->and($output)->toContain('Controller')
            ->and($output)->toContain('Download response')
            ->and($output)->toContain('44.1KB')
            ->and($output)->toContain('5 queries, 1 slow, 2 duplicate');
    });

    it('maps gateway app-not-found to the profile target_not_found failure', function (): void {
        fakeGateway(fakeErrorEnvelope('app.not_found', "App 'missing' not found or not visible.", ['app' => 'missing']), 404);

        [$exitCode, $output] = runCommand($this, 'profile', [
            'target' => 'missing',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('target_not_found')
            ->and($decoded['error']['message'])->toBe('No linked app found for the requested profile target.')
            ->and($decoded['error']['meta']['app'])->toBe('missing');
    });

    it('preserves caller-side profile request failure diagnostics', function (): void {
        fakeLocalProfile(fakeProfileData([
            'request' => [
                'method' => 'GET',
                'url' => 'https://docs.test/',
                'uri' => '/',
                'status' => null,
                'bytes' => 0,
                'completed' => false,
            ],
            'error' => [
                'message' => 'Operation timed out',
            ],
        ]));
        fakeGateway(fakeSuccessEnvelope(fakeProfileResolutionData()));

        [$exitCode, $output] = runCommand($this, 'profile', [
            'target' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('profile_request_failed')
            ->and($decoded['error']['data']['request']['completed'])->toBeFalse()
            ->and($decoded['error']['data']['profile_error']['message'])->toBe('Operation timed out')
            ->and($decoded['error']['meta']['origin'])->toBe('caller');
    });

    it('renders caller-side profile request failure diagnostics in human mode', function (): void {
        fakeLocalProfile(fakeProfileData([
            'request' => [
                'method' => 'GET',
                'url' => 'https://docs.test/',
                'uri' => '/',
                'status' => null,
                'bytes' => 0,
                'completed' => false,
            ],
            'error' => [
                'message' => 'Operation timed out',
            ],
        ]));
        fakeGateway(fakeSuccessEnvelope(fakeProfileResolutionData()));

        [$exitCode, $output] = runCommand($this, 'profile', ['target' => 'docs']);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Failed to complete profile request.')
            ->and($output)->toContain('Origin: caller')
            ->and($output)->toContain('URL: https://docs.test/')
            ->and($output)->toContain('Error: Operation timed out');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('No route to host');

        [$exitCode, $output] = runCommand($this, 'profile', [
            'target' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});

/**
 * @return array<string, mixed>
 */
function fakeProfileData(array $overrides = []): array
{
    return array_replace_recursive([
        'source' => 'baseline',
        'instrumented' => false,
        'auth_mode' => 'guest',
        'request_id' => 'profile-request-id',
        'origin' => 'caller',
        'target' => [
            'app' => 'docs',
            'workspace' => null,
            'node' => 'app-1',
            'domain' => 'docs.test',
        ],
        'request' => [
            'method' => 'GET',
            'url' => 'https://docs.test/',
            'uri' => '/',
            'status' => 200,
            'bytes' => 45120,
            'completed' => true,
        ],
        'timings' => [
            'dns_ms' => 2.15,
            'connect_ms' => 5.2,
            'tls_ms' => 8.1,
            'ttfb_ms' => 110.3,
            'download_ms' => 5.12,
            'total_ms' => 115.42,
        ],
        'response_headers' => [
            'x-caddy-end' => 109.2,
        ],
        'error' => null,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $profile
 */
function fakeLocalProfile(array $profile): ProfileCommandFakeProfiler
{
    $profiler = new ProfileCommandFakeProfiler($profile);

    app()->instance(ProfileRequestProfiler::class, $profiler);

    return $profiler;
}

final class ProfileCommandFakeProfiler implements ProfileRequestProfiler
{
    /**
     * @var list<array{url: string, headers: array<string, string>}>
     */
    public array $calls = [];

    /**
     * @param  array<string, mixed>  $profile
     */
    public function __construct(
        private readonly array $profile,
    ) {}

    /**
     * @param  array<string, string>  $headers
     * @return array<string, mixed>
     */
    public function profile(string $url, array $headers = []): array
    {
        $this->calls[] = compact('url', 'headers');

        return $this->profile;
    }
}

/**
 * @return array<string, mixed>
 */
function fakeProfileResolutionData(array $overrides = []): array
{
    return array_replace_recursive([
        'auth_mode' => 'guest',
        'target' => [
            'app' => 'docs',
            'workspace' => null,
            'node' => 'app-1',
            'domain' => 'docs.test',
        ],
        'request' => [
            'method' => 'GET',
            'url' => 'https://docs.test/',
            'uri' => '/',
        ],
    ], $overrides);
}

/**
 * @param  array<string, mixed>|null  $toolbar
 * @return array{process: resource, pipes: array<int, resource>, directory: string, capture: string, port: int}
 */
function startProfileCommandTestServer(?array $toolbar = null, ?string $redirectLocation = null): array
{
    $directory = sys_get_temp_dir().'/orbit-profile-command-'.bin2hex(random_bytes(4));

    mkdir($directory, 0777, true);

    $capture = "{$directory}/capture.json";
    $router = "{$directory}/router.php";
    $toolbarHeader = $toolbar === null ? '' : base64_encode(json_encode($toolbar, JSON_THROW_ON_ERROR));

    file_put_contents($router, <<<'PHP'
<?php

if (($_SERVER['REQUEST_URI'] ?? '') === '/__ready') {
    echo 'ready';

    return;
}

$capture = getenv('ORBIT_PROFILE_CAPTURE');

if (is_string($capture) && $capture !== '') {
    file_put_contents($capture, json_encode([
        'method' => $_SERVER['REQUEST_METHOD'] ?? null,
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
        'auth' => $_SERVER['HTTP_X_TOOLBAR_AUTH'] ?? null,
        'user' => $_SERVER['HTTP_X_TOOLBAR_USER'] ?? null,
        'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? null,
    ], JSON_THROW_ON_ERROR));
}

$toolbarHeader = getenv('ORBIT_PROFILE_TOOLBAR_SUMMARY');

if (($_SERVER['REQUEST_URI'] ?? '') === '/redirect') {
    $redirectLocation = getenv('ORBIT_PROFILE_REDIRECT_LOCATION');

    if (is_string($redirectLocation) && $redirectLocation !== '') {
        http_response_code(302);
        header("Location: {$redirectLocation}");
        echo 'redirecting';

        return;
    }
}

if (is_string($toolbarHeader) && $toolbarHeader !== '') {
    header("X-Toolbar-Summary: {$toolbarHeader}");
}

header('Content-Type: text/plain');
echo 'profile-ok';
PHP);

    $port = unusedProfileCommandTestPort();
    $process = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $directory, $router],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $directory,
        [
            'ORBIT_PROFILE_CAPTURE' => $capture,
            'ORBIT_PROFILE_REDIRECT_LOCATION' => $redirectLocation ?? '',
            'ORBIT_PROFILE_TOOLBAR_SUMMARY' => $toolbarHeader,
        ],
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start profile command test server.');
    }

    for ($attempt = 0; $attempt < 50; $attempt++) {
        if (@file_get_contents("http://127.0.0.1:{$port}/__ready") === 'ready') {
            return [
                'process' => $process,
                'pipes' => $pipes,
                'directory' => $directory,
                'capture' => $capture,
                'port' => $port,
            ];
        }

        usleep(100_000);
    }

    stopProfileCommandTestServer([
        'process' => $process,
        'pipes' => $pipes,
        'directory' => $directory,
        'capture' => $capture,
        'port' => $port,
    ]);

    throw new RuntimeException('Profile command test server did not become ready.');
}

/**
 * @param  array{process: resource, pipes: array<int, resource>, directory: string, capture: string, port: int}  $server
 */
function stopProfileCommandTestServer(array $server): void
{
    foreach ($server['pipes'] as $pipe) {
        fclose($pipe);
    }

    proc_terminate($server['process']);
    proc_close($server['process']);

    if (is_dir($server['directory'])) {
        array_map('unlink', glob($server['directory'].'/*') ?: []);
        rmdir($server['directory']);
    }
}

function unusedProfileCommandTestPort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');

    if (! is_resource($socket)) {
        throw new RuntimeException('Unable to reserve a profile command test port.');
    }

    $name = stream_socket_get_name($socket, false);
    fclose($socket);

    if (! is_string($name) || ! str_contains($name, ':')) {
        throw new RuntimeException('Unable to determine profile command test port.');
    }

    return (int) substr(strrchr($name, ':'), 1);
}

/**
 * @return array<string, string>
 */
function profileRequestQuery(Request $request): array
{
    $query = parse_url($request->url(), PHP_URL_QUERY);

    if (! is_string($query)) {
        return [];
    }

    parse_str($query, $parsed);

    return array_map(static fn (mixed $value): string => (string) $value, $parsed);
}

function profileRequestPath(Request $request): string
{
    $path = parse_url($request->url(), PHP_URL_PATH);

    return is_string($path) ? $path : '';
}

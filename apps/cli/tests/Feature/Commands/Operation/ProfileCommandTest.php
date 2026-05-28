<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('profile', function (): void {
    it('returns profile data as a canonical JSON envelope and forwards profile options', function (): void {
        fakeGateway(fakeSuccessEnvelope(fakeProfileData(['auth_mode' => 'user']), ['warnings' => []]));

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
                && profileRequestPath($request) === '/api/profile'
                && $query['target'] === 'docs'
                && $query['uri'] === '/login'
                && $query['auth_mode'] === 'user'
                && $query['user'] === '42'
                && $query['node'] === 'app-1';
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['request']['url'])->toBe('https://docs.test/')
            ->and($decoded['success']['data']['auth_mode'])->toBe('user')
            ->and($decoded['success']['meta']['warnings'])->toBe([]);
    });

    it('splits full URL targets into gateway target and request URI', function (): void {
        fakeGateway(fakeSuccessEnvelope(fakeProfileData()));

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
        fakeGateway(fakeSuccessEnvelope(fakeProfileData(['auth_mode' => 'first-user'])));

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

        expect($exitCode)->toBe(0);
    });

    it('forwards the host current working directory when target is omitted', function (): void {
        $previousHostCwd = getenv('ORBIT_HOST_CWD');

        try {
            putenv('ORBIT_HOST_CWD=/home/nick/sites/docs/current');
            fakeGateway(fakeSuccessEnvelope(fakeProfileData()));

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
        fakeGateway(fakeSuccessEnvelope(fakeProfileData([
            'toolbar' => [
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
            ],
        ])));

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

    it('preserves profile request failure diagnostics from the gateway', function (): void {
        fakeGateway([
            'error' => [
                'code' => 'profile_request_failed',
                'message' => 'Failed to complete profile request.',
                'data' => [
                    'request' => [
                        'method' => 'GET',
                        'url' => 'https://docs.test/',
                        'uri' => '/',
                        'status' => null,
                        'bytes' => 0,
                        'completed' => false,
                    ],
                    'profile_error' => [
                        'message' => 'Operation timed out',
                    ],
                ],
                'meta' => [
                    'origin' => 'gateway',
                    'url' => 'https://docs.test/',
                ],
            ],
        ], 422);

        [$exitCode, $output] = runCommand($this, 'profile', [
            'target' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('profile_request_failed')
            ->and($decoded['error']['data']['request']['completed'])->toBeFalse()
            ->and($decoded['error']['data']['profile_error']['message'])->toBe('Operation timed out')
            ->and($decoded['error']['meta']['origin'])->toBe('gateway');
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
        'origin' => 'gateway',
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
    ], $overrides);
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

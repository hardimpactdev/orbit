<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

describe('app:log registration', function (): void {
    it('is registered as a public command', function (): void {
        expect(array_key_exists('app:log', Artisan::all()))->toBeTrue();
    });
});

/** @mago-expect lint:cyclomatic-complexity */
describe('app:log host resolution', function (): void {
    it('accepts a bare hostname and resolves an exact proxy route', function (): void {
        Http::fake(function (Request $request) {
            $url = urldecode($request->url());

            if (str_contains($url, '/api/instances') && $request->method() === 'GET' && ! str_contains($url, '/log')) {
                return Http::response(fakeSuccessEnvelope([
                    'instances' => [
                        ['app' => 'mealou', 'name' => 'development'],
                    ],
                ]));
            }

            if (str_contains($url, '/api/proxy-routes')) {
                return Http::response(fakeSuccessEnvelope([
                    'routes' => [
                        [
                            'domain' => 'example.test',
                            'owner' => ['type' => 'instance', 'name' => 'docs.development'],
                            'target' => ['type' => 'instance', 'value' => 'docs.development'],
                        ],
                    ],
                ]));
            }

            if (str_contains($url, '/api/instances/docs.development/log')) {
                return Http::response(fakeSuccessEnvelope([
                    'path' => 'storage/logs/laravel.log',
                    'file_exists' => false,
                    'lines' => [],
                    'target' => [
                        'type' => 'instance',
                        'selector' => 'docs.development',
                    ],
                ]));
            }

            return Http::response(['error' => ['code' => 'unexpected']], 500);
        });

        [$exitCode, $output] = runCommand($this, 'app:log', [
            'target' => 'example.test',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['path'])
            ->toBe('storage/logs/laravel.log');
    });

    it('accepts a bare hostname that collides with a registered app.instance selector when it is an exact proxy domain', function (): void {
        Http::fake(function (Request $request) {
            $url = urldecode($request->url());

            if (str_contains($url, '/api/instances') && $request->method() === 'GET' && ! str_contains($url, '/log')) {
                return Http::response(fakeSuccessEnvelope([
                    'instances' => [
                        ['app' => 'servauto-app', 'name' => 'nmbp'],
                    ],
                ]));
            }

            if (str_contains($url, '/api/proxy-routes')) {
                return Http::response(fakeSuccessEnvelope([
                    'routes' => [
                        [
                            'domain' => 'servauto-app.nmbp',
                            'owner' => ['type' => 'instance', 'name' => 'servauto-app.nmbp'],
                            'target' => ['type' => 'instance', 'value' => 'servauto-app.nmbp'],
                        ],
                    ],
                ]));
            }

            if (str_contains($url, '/api/instances/servauto-app.nmbp/log')) {
                return Http::response(fakeSuccessEnvelope([
                    'path' => 'storage/logs/laravel.log',
                    'file_exists' => true,
                    'lines' => ['collision-ok'],
                    'target' => [
                        'type' => 'instance',
                        'selector' => 'servauto-app.nmbp',
                    ],
                ]));
            }

            return Http::response(['error' => ['code' => 'unexpected']], 500);
        });

        [$exitCode, $output] = runCommand($this, 'app:log', [
            'target' => 'servauto-app.nmbp',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['path'])
            ->toBe('storage/logs/laravel.log')
            ->and($decoded['success']['data']['lines'])
            ->toBe(['collision-ok']);

        Http::assertSent(fn (Request $request): bool => str_contains(
            urldecode($request->url()),
            '/api/instances/servauto-app.nmbp/log',
        ));
    });

    it('allows an explicit URL even when the host spelling matches a canonical instance selector', function (): void {
        Http::fake(function (Request $request) {
            $url = urldecode($request->url());

            if (str_contains($url, '/api/proxy-routes')) {
                return Http::response(fakeSuccessEnvelope([
                    'routes' => [
                        [
                            'domain' => 'mealou.development',
                            'owner' => ['type' => 'instance', 'name' => 'docs.development'],
                            'target' => ['type' => 'instance', 'value' => 'docs.development'],
                        ],
                    ],
                ]));
            }

            if (str_contains($url, '/api/instances/docs.development/log')) {
                return Http::response(fakeSuccessEnvelope([
                    'path' => 'storage/logs/laravel.log',
                    'file_exists' => true,
                    'lines' => ['ok'],
                ]));
            }

            return Http::response(['error' => ['code' => 'unexpected']], 500);
        });

        [$exitCode] = runCommand($this, 'app:log', [
            'target' => 'https://mealou.development',
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0);
        Http::assertSent(fn (Request $request): bool => str_contains(
            urldecode($request->url()),
            '/api/instances/docs.development/log',
        ));
    });

    it('rejects dotted non-instance proxy owners as application log targets', function (string $ownerType): void {
        Http::fake(function (Request $request) use ($ownerType) {
            $url = urldecode($request->url());

            if (str_contains($url, '/api/instances') && $request->method() === 'GET' && ! str_contains($url, '/log')) {
                return Http::response(fakeSuccessEnvelope(['instances' => []]));
            }

            if (str_contains($url, '/api/proxy-routes')) {
                return Http::response(fakeSuccessEnvelope([
                    'routes' => [[
                        'domain' => 'service.example.test',
                        'owner' => ['type' => $ownerType, 'name' => 'websocket.orbit'],
                        'target' => ['type' => 'upstream', 'value' => 'websocket.orbit'],
                    ]],
                ]));
            }

            return Http::response(['error' => ['code' => 'unexpected']], 500);
        });

        [$exitCode, $output] = runCommand($this, 'app:log', [
            'target' => 'service.example.test',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed');

        Http::assertNotSent(fn (Request $request): bool => str_contains(urldecode($request->url()), '/log'));
    })->with(['router', 's3', 'tool', 'gateway', 'custom']);

    it('keeps a dotted workspace owner as a workspace log target', function (): void {
        Http::fake(function (Request $request) {
            $url = urldecode($request->url());

            if (str_contains($url, '/api/proxy-routes')) {
                return Http::response(fakeSuccessEnvelope([
                    'routes' => [[
                        'domain' => 'workspace.example.test',
                        'owner' => ['type' => 'workspace', 'name' => 'feature-docs'],
                        'instance' => 'docs.development',
                        'target' => ['type' => 'workspace', 'value' => 'feature-docs'],
                    ]],
                ]));
            }

            if (str_contains($url, '/api/workspaces/feature-docs/log')) {
                return Http::response(fakeSuccessEnvelope([
                    'path' => 'storage/logs/laravel.log',
                    'file_exists' => false,
                    'lines' => [],
                ]));
            }

            return Http::response(['error' => ['code' => 'unexpected']], 500);
        });

        [$exitCode] = runCommand($this, 'app:log', [
            'target' => 'workspace.example.test',
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0);

        Http::assertSent(fn (Request $request): bool => str_contains(
            urldecode($request->url()),
            '/api/workspaces/feature-docs/log',
        ));
        Http::assertNotSent(fn (Request $request): bool => str_contains(
            urldecode($request->url()),
            '/api/instances/feature-docs/log',
        ));
    });

    it('accepts the safe target-only instance projection compatibility form', function (): void {
        Http::fake(function (Request $request) {
            $url = urldecode($request->url());

            if (str_contains($url, '/api/proxy-routes')) {
                return Http::response(fakeSuccessEnvelope([
                    'routes' => [[
                        'domain' => 'legacy.example.test',
                        'target' => ['type' => 'instance', 'value' => 'docs.development'],
                    ]],
                ]));
            }

            if (str_contains($url, '/api/instances/docs.development/log')) {
                return Http::response(fakeSuccessEnvelope([
                    'path' => 'storage/logs/laravel.log',
                    'file_exists' => false,
                    'lines' => [],
                ]));
            }

            return Http::response(['error' => ['code' => 'unexpected']], 500);
        });

        [$exitCode] = runCommand($this, 'app:log', [
            'target' => 'legacy.example.test',
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0);
    });

    it('does not combine a partial owner projection with an instance target fallback', function (): void {
        Http::fake(function (Request $request) {
            $url = urldecode($request->url());

            if (str_contains($url, '/api/instances') && $request->method() === 'GET' && ! str_contains($url, '/log')) {
                return Http::response(fakeSuccessEnvelope(['instances' => []]));
            }

            if (str_contains($url, '/api/proxy-routes')) {
                return Http::response(fakeSuccessEnvelope([
                    'routes' => [[
                        'domain' => 'service.example.test',
                        'owner' => ['name' => 'websocket.orbit'],
                        'target' => ['type' => 'instance', 'value' => 'docs.development'],
                    ]],
                ]));
            }

            return Http::response(['error' => ['code' => 'unexpected']], 500);
        });

        [$exitCode] = runCommand($this, 'app:log', [
            'target' => 'service.example.test',
            '--json' => true,
        ]);

        expect($exitCode)->toBe(1);

        Http::assertNotSent(fn (Request $request): bool => str_contains(urldecode($request->url()), '/log'));
    });
});

describe('app:log validation', function (): void {
    it('rejects invalid URL shapes with credentials', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'app:log', [
            'target' => 'https://user:pass@docs.test',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('validation_failed');
    });

    it('rejects non-default ports', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'app:log', [
            'target' => 'https://docs.test:8443',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('validation_failed');
    });

    it('requires an explicit target for json mode', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'app:log', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['meta']['field'] ?? null)
            ->toBe('target');
    });

    it('requires an explicit target for noninteractive human mode', function (): void {
        Http::fake();

        [$exitCode, $output] = run_application_log_command_noninteractive('app:log');

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toContain('validation_failed')
            ->toContain('URL or hostname target is required');
    });

    it('rejects decimal and scientific --lines values', function (string $lines): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'app:log', [
            'target' => 'example.test',
            '--lines' => $lines,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['meta']['field'] ?? null)
            ->toBe('lines');
    })->with(['1.5', '1e3', '-1', '0', '999999999999999999999999']);

    it('accepts PHP_INT_MAX as a strict positive textual --lines value', function (): void {
        Http::fake(function (Request $request) {
            $url = urldecode($request->url());

            if (str_contains($url, '/api/instances') && $request->method() === 'GET' && ! str_contains($url, '/log')) {
                return Http::response(fakeSuccessEnvelope([
                    'instances' => [],
                ]));
            }

            if (str_contains($url, '/api/proxy-routes')) {
                return Http::response(fakeSuccessEnvelope([
                    'routes' => [
                        [
                            'domain' => 'example.test',
                            'owner' => ['type' => 'instance', 'name' => 'docs.development'],
                            'target' => ['type' => 'instance', 'value' => 'docs.development'],
                        ],
                    ],
                ]));
            }

            if (str_contains($url, '/api/instances/docs.development/log')) {
                return Http::response(fakeSuccessEnvelope([
                    'path' => 'storage/logs/laravel.log',
                    'file_exists' => false,
                    'lines' => [],
                    'lines_requested' => PHP_INT_MAX,
                ]));
            }

            return Http::response(['error' => ['code' => 'unexpected']], 500);
        });

        [$exitCode] = runCommand($this, 'app:log', [
            'target' => 'example.test',
            '--lines' => (string) PHP_INT_MAX,
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0);
        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return (
                str_contains($url, '/api/instances/docs.development/log') && str_contains($url, 'lines='.PHP_INT_MAX)
            );
        });
    });
});

describe('app:log cwd inference', function (): void {
    it('falls back from missing workspace path to unique instance path inventory when interactive', function (): void {
        $previousCwd = getenv('ORBIT_HOST_CWD');
        putenv('ORBIT_HOST_CWD=/srv/apps/docs/app-work');

        try {
            Http::fake(function (Request $request) {
                $url = urldecode($request->url());

                if (str_contains($url, '/api/workspaces/resolve-by-path')) {
                    return Http::response([
                        'error' => [
                            'code' => 'workspace.not_found',
                            'message' => 'No workspace at path.',
                            'meta' => (object) [],
                        ],
                    ], 404);
                }

                if (str_contains($url, '/api/instances') && ! str_contains($url, '/log')) {
                    return Http::response(fakeSuccessEnvelope([
                        'instances' => [
                            ['app' => 'docs', 'name' => 'development', 'path' => '/srv/apps/docs'],
                        ],
                    ]));
                }

                if (str_contains($url, '/api/instances/docs.development/log')) {
                    return Http::response(fakeSuccessEnvelope([
                        'path' => 'storage/logs/laravel.log',
                        'file_exists' => true,
                        'lines' => ['inferred'],
                    ]));
                }

                return Http::response(['error' => ['code' => 'unexpected']], 500);
            });

            [$exitCode, $output] = run_application_log_command_interactive('app:log', [
                '--json' => false,
            ]);

            expect($exitCode)->toBe(0)->and($output)->toContain('inferred');
            Http::assertSent(
                fn (Request $request): bool => (
                    str_contains(urldecode($request->url()), '/api/instances/docs.development/log')
                    && $request->hasHeader('X-Orbit-Application-Log-Requested-Target', 'docs.development')
                ),
            );
        } finally {
            if (is_string($previousCwd) && $previousCwd !== '') {
                putenv('ORBIT_HOST_CWD='.$previousCwd);
            } else {
                putenv('ORBIT_HOST_CWD');
            }
        }
    });
});

<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

describe('instance:log registration', function (): void {
    it('is registered as a public command', function (): void {
        expect(array_key_exists('instance:log', Artisan::all()))->toBeTrue();
    });
});

describe('instance:log resolution', function (): void {
    it('returns the bounded application log envelope with fixed logical path', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'target' => [
                'type' => 'instance',
                'app' => 'docs',
                'instance' => 'development',
                'workspace' => null,
                'selector' => 'docs.development',
            ],
            'node' => 'app-dev-1',
            'path' => 'storage/logs/laravel.log',
            'lines_requested' => 100,
            'file_exists' => false,
            'lines' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'instance:log', [
            'target' => 'docs.development',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return (
                $request->method() === 'GET'
                && str_contains($url, '/api/instances/docs.development/log')
                && str_contains($url, 'lines=100')
            );
        });

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['path'])
            ->toBe('storage/logs/laravel.log')
            ->and($decoded['success']['data']['file_exists'])
            ->toBeFalse();
    });

    it('resolves a registered multi-label mixed-case hostname through proxy normalization', function (): void {
        Http::fake(function (Request $request) {
            $url = urldecode($request->url());

            if (str_contains($url, '/api/proxy-routes')) {
                return Http::response(fakeSuccessEnvelope([
                    'routes' => [
                        [
                            'domain' => 'www.example.test',
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
                    'lines' => ['host-ok'],
                ]));
            }

            return Http::response(['error' => ['code' => 'unexpected']], 500);
        });

        [$exitCode, $output] = runCommand($this, 'instance:log', [
            'target' => 'WWW.Example.Test',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['lines'])
            ->toBe(['host-ok']);
    });

    it('rejects uppercase one-dot tokens that are not registered hosts via proxy-route resolution', function (): void {
        Http::fake([
            'https://gateway.test/api/proxy-routes' => Http::response(fakeSuccessEnvelope([
                'routes' => [],
            ])),
        ]);

        [$exitCode, $output] = runCommand($this, 'instance:log', [
            'target' => 'Docs.Development',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['message'])
            ->toContain("No registered proxy route matches host 'docs.development'")
            ->and($decoded['error']['message'])
            ->not
            ->toContain('lowercase app.instance')
            ->and($decoded['error']['meta']['host'] ?? null)
            ->toBe('docs.development');
    });

    it('rejects unregistered multi-label hostnames via proxy-route resolution, not selector validation', function (): void {
        Http::fake([
            'https://gateway.test/api/proxy-routes' => Http::response(fakeSuccessEnvelope([
                'routes' => [],
            ])),
        ]);

        [$exitCode, $output] = runCommand($this, 'instance:log', [
            'target' => 'WWW.Unregistered.Example.Test',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['message'])
            ->toContain("No registered proxy route matches host 'www.unregistered.example.test'")
            ->and($decoded['error']['message'])
            ->not
            ->toContain('lowercase app.instance')
            ->and($decoded['error']['meta']['host'] ?? null)
            ->toBe('www.unregistered.example.test')
            ->and($decoded['error']['meta']['field'] ?? null)
            ->toBe('target');
    });

    it('rejects extra-segment host shapes that do not match a proxy route', function (): void {
        Http::fake([
            'https://gateway.test/api/proxy-routes' => Http::response(fakeSuccessEnvelope([
                'routes' => [],
            ])),
        ]);

        [$exitCode, $output] = runCommand($this, 'instance:log', [
            'target' => 'docs.development.extra',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['message'])
            ->toContain("No registered proxy route matches host 'docs.development.extra'")
            ->and($decoded['error']['message'])
            ->not->toContain('lowercase app.instance');
    });

    it('forwards explicit lines and node constraint', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'path' => 'storage/logs/laravel.log',
            'lines_requested' => 25,
            'file_exists' => true,
            'lines' => ['first', 'second'],
        ]));

        [$exitCode] = runCommand($this, 'instance:log', [
            'target' => 'docs.development',
            '--lines' => 25,
            '--node' => 'app-dev-1',
            '--json' => true,
        ]);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return str_contains($url, 'lines=25') && str_contains($url, 'node=app-dev-1');
        });

        expect($exitCode)->toBe(0);
    });
});

describe('instance:log validation', function (): void {
    it('rejects --json with --follow before opening a gateway stream', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'instance:log', [
            'target' => 'docs.development',
            '--json' => true,
            '--follow' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('validation_failed');
    });

    it('rejects decimal lines values', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'instance:log', [
            'target' => 'docs.development',
            '--lines' => '1.5',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['meta']['field'] ?? null)
            ->toBe('lines');
    });

    it('requires an explicit target for json mode', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'instance:log', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['meta']['field'] ?? null)
            ->toBe('target');
    });

    it('requires an explicit target for noninteractive human mode', function (): void {
        Http::fake();

        [$exitCode, $output] = run_application_log_command_noninteractive('instance:log');

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toContain('validation_failed')
            ->toContain('instance target is required');
    });

    it('accepts PHP_INT_MAX as a strict positive textual --lines value', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'path' => 'storage/logs/laravel.log',
            'lines_requested' => PHP_INT_MAX,
            'file_exists' => false,
            'lines' => [],
        ]));

        [$exitCode] = runCommand($this, 'instance:log', [
            'target' => 'docs.development',
            '--lines' => (string) PHP_INT_MAX,
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0);
        Http::assertSent(function (Request $request): bool {
            return str_contains(urldecode($request->url()), 'lines='.PHP_INT_MAX);
        });
    });

    it('rejects overflow --lines values above PHP_INT_MAX', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'instance:log', [
            'target' => 'docs.development',
            '--lines' => '999999999999999999999999999',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['meta']['field'] ?? null)
            ->toBe('lines');
    });
});

describe('instance:log cwd inference', function (): void {
    it('infers the instance selector from unique visible owned path inventory when interactive', function (): void {
        $root = '/srv/apps/docs/current-work';
        $previousCwd = getenv('ORBIT_HOST_CWD');
        putenv('ORBIT_HOST_CWD='.$root);

        try {
            Http::fake(function (Request $request) {
                $url = urldecode($request->url());

                if (str_contains($url, '/api/instances') && ! str_contains($url, '/log')) {
                    return Http::response(fakeSuccessEnvelope([
                        'instances' => [
                            [
                                'app' => 'docs',
                                'name' => 'development',
                                'path' => '/srv/apps/docs',
                            ],
                            [
                                'app' => 'other',
                                'name' => 'development',
                                'path' => '/srv/apps/other',
                            ],
                        ],
                    ]));
                }

                if (str_contains($url, '/api/instances/docs.development/log')) {
                    return Http::response(fakeSuccessEnvelope([
                        'path' => 'storage/logs/laravel.log',
                        'file_exists' => true,
                        'lines' => ['instance-inferred'],
                    ]));
                }

                return Http::response(['error' => ['code' => 'unexpected']], 500);
            });

            [$exitCode, $output] = run_application_log_command_interactive('instance:log');

            expect($exitCode)->toBe(0)->and($output)->toContain('instance-inferred');
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

    it('fails interactive cwd inference when multiple instance paths match', function (): void {
        $previousCwd = getenv('ORBIT_HOST_CWD');
        putenv('ORBIT_HOST_CWD=/srv/apps/docs/workspaces/feature');

        try {
            Http::fake(function (Request $request) {
                $url = urldecode($request->url());

                if (str_contains($url, '/api/instances') && ! str_contains($url, '/log')) {
                    return Http::response(fakeSuccessEnvelope([
                        'instances' => [
                            ['app' => 'docs', 'name' => 'development', 'path' => '/srv/apps/docs'],
                            ['app' => 'docs', 'name' => 'staging', 'path' => '/srv/apps/docs/workspaces'],
                        ],
                    ]));
                }

                return Http::response(['error' => ['code' => 'unexpected']], 500);
            });

            [$exitCode, $output] = run_application_log_command_interactive('instance:log');

            expect($exitCode)->toBe(1)->and($output)->toContain('Multiple visible instances');
            Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/log'));
        } finally {
            if (is_string($previousCwd) && $previousCwd !== '') {
                putenv('ORBIT_HOST_CWD='.$previousCwd);
            } else {
                putenv('ORBIT_HOST_CWD');
            }
        }
    });

    it('sends the CLI selector as the requested-target activity header', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'path' => 'storage/logs/laravel.log',
            'file_exists' => false,
            'lines' => [],
        ]));

        [$exitCode] = runCommand($this, 'instance:log', [
            'target' => 'docs.development',
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0);
        Http::assertSent(
            fn (Request $request): bool => (
                str_contains(urldecode($request->url()), '/api/instances/docs.development/log')
                && $request->hasHeader('X-Orbit-Application-Log-Requested-Target', 'docs.development')
            ),
        );
    });
});

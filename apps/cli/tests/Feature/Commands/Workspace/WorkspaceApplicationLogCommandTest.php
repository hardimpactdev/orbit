<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('workspace:log application log reads', function (): void {
    it('sends parent instance on bounded workspace application log reads', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'target' => [
                'type' => 'workspace',
                'app' => 'docs',
                'instance' => 'development',
                'workspace' => 'feature-docs',
                'selector' => 'feature-docs',
            ],
            'node' => 'app-dev-1',
            'path' => 'storage/logs/laravel.log',
            'lines_requested' => 100,
            'file_exists' => true,
            'lines' => ['workspace line'],
        ]));

        [$exitCode, $output] = runCommand($this, 'workspace:log', [
            'target' => 'feature-docs',
            '--instance' => 'docs.development',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return (
                $request->method() === 'GET'
                && str_contains($url, '/api/workspaces/feature-docs/log')
                && str_contains($url, 'instance=docs.development')
                && str_contains($url, 'lines=100')
                && $request->hasHeader('X-Orbit-Application-Log-Requested-Target', 'feature-docs')
            );
        });

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['path'])
            ->toBe('storage/logs/laravel.log')
            ->and($decoded['success']['data']['target']['selector'])
            ->toBe('feature-docs');
    });

    it('rejects instance hosts for workspace:log', function (): void {
        Http::fake(function (Request $request) {
            if (str_contains(urldecode($request->url()), '/api/proxy-routes')) {
                return Http::response(fakeSuccessEnvelope([
                    'routes' => [
                        [
                            'domain' => 'docs.test',
                            'owner' => ['type' => 'instance', 'name' => 'docs.development'],
                            'target' => ['type' => 'instance', 'value' => 'docs.development'],
                        ],
                    ],
                ]));
            }

            return Http::response(['error' => ['code' => 'unexpected']], 500);
        });

        [$exitCode, $output] = runCommand($this, 'workspace:log', [
            'target' => 'https://docs.test',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['meta']['reason'] ?? null)
            ->toBe('wrong_target_type');
    });
});

describe('workspace:log application log validation', function (): void {
    it('rejects --json with --follow', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'workspace:log', [
            'target' => 'feature-docs',
            '--instance' => 'docs.development',
            '--json' => true,
            '--follow' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('validation_failed');
    });

    it('requires an explicit target for json mode', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'workspace:log', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['meta']['field'] ?? null)
            ->toBe('target');
    });

    it('requires an explicit target for noninteractive human mode', function (): void {
        Http::fake();

        [$exitCode, $output] = run_application_log_command_noninteractive('workspace:log');

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toContain('validation_failed')
            ->toContain('workspace target is required');
    });

    it('rejects non-canonical --instance values', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'workspace:log', [
            'target' => 'feature-docs',
            '--instance' => 'Docs.Development',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['meta']['field'] ?? null)
            ->toBe('instance');
    });
});

describe('workspace:log application log cwd inference', function (): void {
    it('infers workspace target from resolve-by-path when interactive', function (): void {
        $root = sys_get_temp_dir().'/orbit-workspace-log-cwd-'.uniqid('', true);
        mkdir($root, 0777, true);
        $previousCwd = getenv('ORBIT_HOST_CWD');
        putenv('ORBIT_HOST_CWD='.$root);

        try {
            Http::fake(function (Request $request) {
                $url = urldecode($request->url());

                if (str_contains($url, '/api/workspaces/resolve-by-path')) {
                    return Http::response(fakeSuccessEnvelope([
                        'name' => 'feature-docs',
                        'app' => 'docs',
                        'instance' => 'development',
                        'path' => '/srv/apps/docs/workspaces/feature-docs',
                    ]));
                }

                if (str_contains($url, '/api/workspaces/feature-docs/log')) {
                    return Http::response(fakeSuccessEnvelope([
                        'path' => 'storage/logs/laravel.log',
                        'file_exists' => true,
                        'lines' => ['workspace-inferred'],
                    ]));
                }

                return Http::response(['error' => ['code' => 'unexpected']], 500);
            });

            [$exitCode, $output] = run_application_log_command_interactive('workspace:log');

            expect($exitCode)->toBe(0)->and($output)->toContain('workspace-inferred');
            Http::assertSent(function (Request $request): bool {
                $url = urldecode($request->url());

                return (
                    str_contains($url, '/api/workspaces/feature-docs/log')
                    && str_contains($url, 'instance=docs.development')
                );
            });
        } finally {
            if (is_string($previousCwd) && $previousCwd !== '') {
                putenv('ORBIT_HOST_CWD='.$previousCwd);
            } else {
                putenv('ORBIT_HOST_CWD');
            }

            if (is_dir($root)) {
                rmdir($root);
            }
        }
    });
});

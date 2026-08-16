<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('app:show', function (): void {
    it('returns a canonical success envelope in JSON mode and forwards the app path', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => ['name' => 'orbit-docs', 'node' => 'app-1'],
            'details' => ['domain' => 'orbit-docs.test'],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:show', [
            'app' => 'orbit-docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/api/apps/orbit-docs'),
        );

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['app']['name'])->toBe('orbit-docs');
    });

    it('fails validation when no project can be resolved', function (): void {
        [$exitCode, $output] = runCommand($this, 'app:show', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('app');
    });

    it('renders human output with instance and workspace placement rows', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'app' => [
                'name' => 'orbit-docs',
                'repository' => 'orbit/docs',
                'php_version' => '8.5',
                'dependency_audit_status' => 'findings',
                'dependency_warning_count' => 14,
                'dependency_danger_count' => 2,
            ],
            'details' => [
                'instances' => [
                    [
                        'name' => 'development',
                        'driver' => 'orbit',
                        'node' => 'app-1',
                        'url' => 'https://orbit-docs.test',
                        'workspaces' => [
                            [
                                'name' => 'feature-a',
                                'url' => 'https://feature-a.orbit-docs.test',
                                'lifecycle_status' => 'expected',
                            ],
                            [
                                'name' => 'feature-b',
                                'url' => 'https://feature-b.orbit-docs.test',
                                'lifecycle_status' => 'expected',
                            ],
                        ],
                    ],
                    [
                        'name' => 'production',
                        'driver' => 'laravel-cloud',
                        'node' => null,
                        'url' => 'https://orbit-docs.example.com',
                        'workspaces' => [],
                    ],
                ],
                'routes' => [['host' => 'orbit-docs.test']],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:show', ['app' => 'orbit-docs']);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('App: orbit-docs')
            ->and($output)
            ->toContain('Repository')
            ->and($output)
            ->toContain('NAME')
            ->and($output)
            ->toContain('DRIVER')
            ->and($output)
            ->toContain('NODE')
            ->and($output)
            ->toContain('URL')
            ->and($output)
            ->toContain('APP DEPS')
            ->and($output)
            ->toContain('development')
            ->and($output)
            ->toContain('orbit')
            ->and($output)
            ->toContain('app-1')
            ->and($output)
            ->toContain('https://orbit-docs.test')
            ->and($output)
            ->toContain('├─ feature-a')
            ->and($output)
            ->toContain('└─ feature-b')
            ->and($output)
            ->toContain('https://feature-a.orbit-docs.test')
            ->and($output)
            ->toContain('production')
            ->and($output)
            ->toContain('laravel-cloud')
            ->and($output)
            ->toContain('findings (2 danger, 14 warning)')
            ->and($output)
            ->not->toContain('Domain')->and($output)
            ->not->toContain('Path')->and($output)
            ->not->toContain('Root')->and($output)
            ->not->toContain('app: {');
    });

    it('preserves structured gateway errors', function (): void {
        fakeGateway(fakeErrorEnvelope('app.not_found', 'App not found.', [
            'app' => 'missing-app',
        ]), 404);

        [$exitCode, $output] = runCommand($this, 'app:show', [
            'app' => 'missing-app',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('app.not_found')
            ->and($decoded['error']['meta']['app'])
            ->toBe('missing-app');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('No route to host');

        [$exitCode, $output] = runCommand($this, 'app:show', [
            'app' => 'orbit-docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});

<?php

declare(strict_types=1);

use App\Services\GatewayApiClient;
use App\Services\OrbitConfigStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function create_app_list_config_store(string $filename, ?string $defaultNode = null): OrbitConfigStore
{
    $store = new OrbitConfigStore(overridePath: base_path($filename));
    remove_app_list_config_store($store);

    if ($defaultNode !== null) {
        $store->save(['defaults' => ['node' => $defaultNode, 'profile' => null]]);
    }

    app()->instance(OrbitConfigStore::class, $store);

    return $store;
}

function remove_app_list_config_store(OrbitConfigStore $store): void
{
    if (is_file($store->path())) {
        unlink($store->path());
    }
}

describe('app:list', function (): void {
    it('returns a canonical success envelope in JSON mode and forwards supported filters', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'apps' => [
                ['name' => 'orbit-docs', 'node' => 'app-1'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:list', [
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => (
                $request->method() === 'GET'
                && str_contains($request->url(), '/api/apps')
                && str_contains($request->url(), 'node=app-1')
                && ! str_contains($request->url(), 'environment=')
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success'])
            ->toHaveKey('meta')
            ->and($decoded['success']['meta'])
            ->toBeArray()
            ->toBeEmpty()
            ->and($decoded['success']['data']['apps'][0]['name'])
            ->toBe('orbit-docs');
    });

    it('uses the local default node when no node filter is supplied', function (): void {
        $store = create_app_list_config_store('tests/.tmp-app-list-config.json', defaultNode: 'default-app');

        try {
            fakeGateway(fakeSuccessEnvelope([
                'apps' => [
                    ['name' => 'orbit-docs', 'node' => 'default-app'],
                ],
            ]));

            [$exitCode, $output] = runCommand($this, 'app:list', ['--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            Http::assertSent(function (Request $request): bool {
                $url = urldecode($request->url());

                return (
                    $request->method() === 'GET'
                    && str_contains($url, '/api/apps')
                    && str_contains($url, 'node=default-app')
                );
            });

            expect($exitCode)
                ->toBe(0)
                ->and($decoded['success']['data']['apps'][0]['node'])
                ->toBe('default-app');
        } finally {
            remove_app_list_config_store($store);
        }
    });

    it('falls back to caller node scope when no default node is configured', function (): void {
        $store = create_app_list_config_store('tests/.tmp-app-list-empty-config.json');

        try {
            config()->set('orbit.gateway.url', 'https://gateway.test');
            config()->set('orbit.gateway.timeout', 30);
            app()->forgetInstance(GatewayApiClient::class);

            $appRequestUrl = null;

            Http::fake(function (Request $request) use (&$appRequestUrl) {
                if (str_contains($request->url(), '/api/me')) {
                    return Http::response(fakeSuccessEnvelope([
                        'self' => [
                            'name' => 'caller-node',
                            'status' => 'active',
                        ],
                    ]));
                }

                $appRequestUrl = urldecode($request->url());

                return Http::response(fakeSuccessEnvelope([
                    'apps' => [
                        ['name' => 'orbit-docs', 'node' => 'caller-node'],
                    ],
                ]));
            });

            [$exitCode, $output] = runCommand($this, 'app:list', ['--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            Http::assertSent(
                fn (Request $request): bool => $request->method() === 'GET' && str_contains($request->url(), '/api/me'),
            );

            expect($appRequestUrl)
                ->toContain('/api/apps')
                ->toContain('node=caller-node');

            expect($exitCode)
                ->toBe(0)
                ->and($decoded['success']['data']['apps'][0]['node'])
                ->toBe('caller-node');
        } finally {
            remove_app_list_config_store($store);
        }
    });

    it('fails instead of listing every node when no effective node can be resolved', function (): void {
        $store = create_app_list_config_store('tests/.tmp-app-list-unresolved-config.json');

        try {
            config()->set('orbit.gateway.url', 'https://gateway.test');
            config()->set('orbit.gateway.timeout', 30);
            app()->forgetInstance(GatewayApiClient::class);

            Http::fake(['https://gateway.test/*' => Http::response(fakeSuccessEnvelope(['self' => []]))]);

            [$exitCode, $output] = runCommand($this, 'app:list', ['--json' => true]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            Http::assertNotSent(
                fn (Request $request): bool => $request->method() === 'GET'
                && str_contains($request->url(), '/api/apps'),
            );

            expect($exitCode)
                ->toBe(1)
                ->and($decoded['error']['code'])
                ->toBe('gateway_unavailable');
        } finally {
            remove_app_list_config_store($store);
        }
    });

    it('keeps an explicit node filter instead of the local default node', function (): void {
        $store = create_app_list_config_store(
            'tests/.tmp-app-list-explicit-node-config.json',
            defaultNode: 'default-app',
        );

        try {
            fakeGateway(fakeSuccessEnvelope([
                'apps' => [
                    ['name' => 'orbit-docs', 'node' => 'app-2'],
                ],
            ]));

            [$exitCode] = runCommand($this, 'app:list', [
                '--node' => 'app-2',
                '--json' => true,
            ]);

            Http::assertSent(function (Request $request): bool {
                $url = urldecode($request->url());

                return (
                    $request->method() === 'GET'
                    && str_contains($url, '/api/apps')
                    && str_contains($url, 'node=app-2')
                    && ! str_contains($url, 'node=default-app')
                );
            });

            expect($exitCode)->toBe(0);
        } finally {
            remove_app_list_config_store($store);
        }
    });

    it('does not expose the retired environment filter', function (): void {
        $command = app(Kernel::class)->all()['app:list'];

        expect($command->getDefinition()->hasOption('environment'))->toBeFalse();
    });

    it('renders human output grouped by node as tables with workspace child rows', function (): void {
        $store = create_app_list_config_store('tests/.tmp-app-list-render-config.json', defaultNode: 'app-1');

        try {
            fakeGateway(fakeSuccessEnvelope([
                'apps' => [
                    [
                        'name' => 'docs',
                        'node' => 'app-1',
                        'url' => 'https://docs.test',
                        'workspaces' => [
                            [
                                'name' => 'feature-a',
                                'url' => 'https://feature-a.docs.test',
                                'lifecycle_status' => 'active',
                            ],
                            [
                                'name' => 'feature-b',
                                'url' => 'https://feature-b.docs.test',
                                'lifecycle_status' => 'setting_up',
                            ],
                        ],
                    ],
                    [
                        'name' => 'blog',
                        'node' => 'app-1',
                        'url' => 'https://blog.test',
                        'workspaces' => [],
                    ],
                    [
                        'name' => 'api',
                        'node' => 'app-2',
                        'url' => 'https://api.test',
                        'workspaces' => [],
                    ],
                ],
            ]));

            [$exitCode, $output] = runCommand($this, 'app:list');

            expect($exitCode)
                ->toBe(0)
                ->and($output)
                ->toContain('Node: app-1')
                ->and($output)
                ->toContain('Node: app-2')
                ->and($output)
                ->toContain('NAME')
                ->and($output)
                ->toContain('URL')
                ->and($output)
                ->toContain('STATUS')
                ->and($output)
                ->toContain('docs')
                ->and($output)
                ->toContain('blog')
                ->and($output)
                ->toContain('api')
                ->and($output)
                ->toContain('expected')
                ->and($output)
                ->toContain('├─ feature-a')
                ->and($output)
                ->toContain('└─ feature-b')
                ->and($output)
                ->toContain('active')
                ->and($output)
                ->toContain('setting_up')
                ->and($output)
                ->not->toContain('apps: [')->and($output)
                ->not->toContain('"lifecycle_status"');
        } finally {
            remove_app_list_config_store($store);
        }
    });

    it('renders human empty output when no apps are visible', function (): void {
        $store = create_app_list_config_store('tests/.tmp-app-list-empty-output-config.json', defaultNode: 'app-1');

        try {
            fakeGateway(fakeSuccessEnvelope([
                'apps' => [],
            ]));

            [$exitCode, $output] = runCommand($this, 'app:list');

            expect($exitCode)->toBe(0)->and($output)->toBe('No apps found.');
        } finally {
            remove_app_list_config_store($store);
        }
    });

    it('surfaces gateway_unavailable on gateway HTTP errors', function (): void {
        fakeGateway(['message' => 'Bad gateway'], 502);

        [$exitCode, $output] = runCommand($this, 'app:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('gateway_unavailable');
    });

    it('preserves structured gateway authorization failures', function (): void {
        fakeGateway(fakeErrorEnvelope('authorization_failed', 'Missing app read permission.', [
            'missing_permission' => 'app:read',
        ]), 403);

        [$exitCode, $output] = runCommand($this, 'app:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('authorization_failed')
            ->and($decoded['error']['meta']['missing_permission'])
            ->toBe('app:read');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('Operation timed out');

        [$exitCode, $output] = runCommand($this, 'app:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});

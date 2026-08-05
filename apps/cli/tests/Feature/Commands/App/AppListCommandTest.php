<?php

declare(strict_types=1);

use App\Services\GatewayApiClient;
use App\Services\OrbitConfigStore;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;

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

function strip_app_list_ansi(string $value): string
{
    return preg_replace(pattern: '/\e\[[0-9;?]*[a-zA-Z]/', replacement: '', subject: $value) ?? $value;
}

describe('app:list', function (): void {
    it('returns a canonical success envelope in JSON mode without node scope', function (): void {
        $store = create_app_list_config_store('tests/.tmp-app-list-config.json', defaultNode: 'default-app');

        try {
            fakeGateway(fakeSuccessEnvelope([
                'apps' => [
                    ['name' => 'orbit-docs', 'node' => 'app-1'],
                ],
            ]));

            [$exitCode, $output] = runCommand($this, 'app:list', [
                '--json' => true,
            ]);

            $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

            Http::assertSent(
                fn (Request $request): bool => (
                    $request->method() === 'GET'
                    && str_contains($request->url(), '/api/apps')
                    && ! str_contains($request->url(), 'node=')
                    && ! str_contains($request->url(), 'environment=')
                ),
            );

            Http::assertNotSent(
                fn (Request $request): bool => $request->method() === 'GET' && str_contains($request->url(), '/api/me'),
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
        } finally {
            remove_app_list_config_store($store);
        }
    });

    it('does not expose node or environment filters', function (): void {
        $command = app(Kernel::class)->all()['app:list'];

        expect($command->getDefinition()->hasOption('node'))
            ->toBeFalse()
            ->and($command->getDefinition()->hasOption('environment'))
            ->toBeFalse();
    });

    it('renders the Laravel Prompts datatable columns and opens the selected project', function (): void {
        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);

        Http::fake(function (Request $request) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if ($request->method() === 'GET' && $path === '/api/apps') {
                return Http::response(fakeSuccessEnvelope([
                    'apps' => [
                        [
                            'name' => 'docs',
                            'repository' => 'git@github.com:orbit/docs.git',
                            'instance_count' => 17,
                            'workspace_count' => 23,
                        ],
                        [
                            'name' => 'blog',
                            'repository' => null,
                            'instance_count' => 1,
                            'workspace_count' => 0,
                        ],
                    ],
                ]));
            }

            if ($request->method() === 'GET' && $path === '/api/apps/docs') {
                return Http::response(fakeSuccessEnvelope([
                    'app' => [
                        'name' => 'docs',
                        'repository' => 'git@github.com:orbit/docs.git',
                    ],
                    'details' => [
                        'domain' => 'docs.test',
                        'instances' => [
                            [
                                'name' => 'development',
                                'driver' => 'orbit',
                                'node' => 'app-1',
                                'url' => 'https://docs.test',
                                'workspaces' => [],
                            ],
                        ],
                    ],
                ]));
            }

            return Http::response(
                fakeErrorEnvelope(code: 'unexpected_request', message: 'Unexpected gateway request.'),
                500,
            );
        });

        Prompt::fake([Key::ENTER]);

        [$exitCode, $output] = runCommand($this, 'app:list');
        $plain = strip_app_list_ansi($output);

        expect($exitCode)
            ->toBe(0)
            ->and($plain)
            ->toContain('Select an app')
            ->and($plain)
            ->toContain('Name')
            ->and($plain)
            ->toContain('Repository')
            ->and($plain)
            ->toContain('Instances')
            ->and($plain)
            ->toContain('Workspaces')
            ->and($plain)
            ->toContain('git@github.com:orbit/docs.git')
            ->and($plain)
            ->toContain('17')
            ->and($plain)
            ->toContain('23')
            ->and($plain)
            ->toContain('App: docs')
            ->and($plain)
            ->toContain('development')
            ->and($plain)
            ->toContain('https://docs.test')
            ->and($plain)
            ->not->toContain('Repository:')->and($plain)
            ->not->toContain('Instances:')->and($plain)
            ->not->toContain('Workspaces:')
            ->not->toContain('apps: [')->and($output)
            ->not->toContain('"lifecycle_status"');

        Http::assertSentCount(2);
    });

    it('requires json mode when app selection is non-interactive', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'app:list', [
            '--no-interaction' => true,
        ]);

        expect($exitCode)
            ->toBe(1)
            ->and($output)
            ->toContain('Interactive app selection requires a terminal.')
            ->and($output)
            ->toContain('Use --json for non-interactive output.');

        Http::assertNothingSent();
    });

    it('renders human empty output when no apps are visible', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'apps' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:list');

        expect($exitCode)->toBe(0)->and($output)->toBe('No apps found.');
    });

    it('surfaces gateway_unavailable on gateway HTTP errors', function (): void {
        fakeGateway(['message' => 'Bad gateway'], 502);

        [$exitCode, $output] = runCommand($this, 'app:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('gateway_unavailable');
    });

    it('preserves structured gateway authorization failures', function (): void {
        fakeGateway(fakeErrorEnvelope('authorization_failed', 'Missing project read permission.', [
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

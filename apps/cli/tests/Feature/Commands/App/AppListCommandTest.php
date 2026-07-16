<?php

declare(strict_types=1);

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

function strip_app_list_ansi(string $value): string
{
    return preg_replace('/\e\[[0-9;?]*[a-zA-Z]/', '', $value) ?? $value;
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

    it('renders logical apps as a data list with placement counts', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'apps' => [
                [
                    'name' => 'docs',
                    'repository' => 'git@github.com:orbit/docs.git',
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
                    'repository' => null,
                    'workspaces' => [],
                ],
            ],
            'inventory' => [
                [
                    'app' => 'docs',
                    'instance_count' => 2,
                    'workspace_count' => 2,
                ],
                [
                    'app' => 'blog',
                    'instance_count' => 1,
                    'workspace_count' => 0,
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:list');
        $plain = strip_app_list_ansi($output);

        expect($exitCode)
            ->toBe(0)
            ->and($plain)
            ->toContain('Apps')
            ->and($plain)
            ->toContain('docs')
            ->and($plain)
            ->toContain('Repository:')
            ->and($plain)
            ->toContain('git@github.com:orbit/docs.git')
            ->and($plain)
            ->toContain('Instances: 2')
            ->and($plain)
            ->toContain('Workspaces: 2')
            ->and($plain)
            ->toContain('blog')
            ->and($plain)
            ->toContain('Instances: 1')
            ->and($plain)
            ->toContain('Workspaces: 0')
            ->and($plain)
            ->not->toContain('NAME')->and($plain)
            ->not->toContain('STATUS')->and($plain)
            ->not->toContain('feature-a')->and($plain)
            ->not->toContain('feature-b')->and($plain)
            ->not->toContain('apps: [')->and($output)
            ->not->toContain('"lifecycle_status"');
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

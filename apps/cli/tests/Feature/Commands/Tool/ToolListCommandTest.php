<?php

declare(strict_types=1);

use App\Services\GatewayApiClient;
use App\Services\OrbitConfigStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function create_tool_list_config_store(string $filename, ?string $defaultNode = null): OrbitConfigStore
{
    $store = new OrbitConfigStore(overridePath: base_path($filename));
    remove_tool_list_config_store($store);

    if ($defaultNode !== null) {
        $store->save(['defaults' => ['node' => $defaultNode, 'profile' => null]]);
    }

    app()->instance(OrbitConfigStore::class, $store);

    return $store;
}

function remove_tool_list_config_store(OrbitConfigStore $store): void
{
    if (is_file($store->path())) {
        unlink($store->path());
    }
}

function strip_tool_list_ansi(string $value): string
{
    return preg_replace('/\e\[[0-9;?]*[a-zA-Z]/', '', $value) ?? $value;
}

describe('tool:list', function (): void {
    it('returns a canonical success envelope in JSON mode and forwards explicit node filters', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'tools' => [
                [
                    'name' => 'composer',
                    'node' => 'app-1',
                    'expected_state' => 'installed',
                    'observed_state' => null,
                    'observed_version' => null,
                    'version' => '2.8',
                    'managed' => true,
                    'endpoints' => [],
                ],
            ],
        ], ['node' => 'app-1', 'count' => 1]));

        [$exitCode, $output] = runCommand($this, 'tool:list', [
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'GET' && str_contains($url, '/api/tools') && str_contains($url, 'node=app-1');
        });

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['tools'][0])
            ->toBe([
                'name' => 'composer',
                'node' => 'app-1',
                'expected_state' => 'installed',
                'observed_state' => null,
                'observed_version' => null,
                'version' => '2.8',
                'managed' => true,
                'endpoints' => [],
            ])
            ->and($decoded['success']['meta']['count'])
            ->toBe(1);
    });

    it('uses the local default node when no target option is provided', function (): void {
        $store = create_tool_list_config_store('tests/.tmp-tool-list-config.json', defaultNode: 'default-app');

        fakeGateway(fakeSuccessEnvelope([
            'tools' => [
                [
                    'name' => 'composer',
                    'node' => 'default-app',
                    'expected_state' => 'installed',
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return str_contains($url, '/api/tools') && str_contains($url, 'node=default-app');
        });

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['tools'][0]['node'])
            ->toBe('default-app');

        remove_tool_list_config_store($store);
    });

    it('falls back to caller node scope when no default node is configured', function (): void {
        $store = create_tool_list_config_store('tests/.tmp-tool-list-empty-config.json');

        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);

        $toolRequestUrl = null;
        $toolRequestData = [];

        Http::fake(function (Request $request) use (&$toolRequestUrl, &$toolRequestData) {
            if (str_contains($request->url(), '/api/me')) {
                return Http::response(fakeSuccessEnvelope([
                    'self' => [
                        'name' => 'caller',
                        'status' => 'active',
                    ],
                ]));
            }

            $toolRequestUrl = urldecode($request->url());
            $toolRequestData = $request->data();

            return Http::response(fakeSuccessEnvelope([
                'tools' => [
                    [
                        'name' => 'composer',
                        'node' => 'caller',
                        'expected_state' => 'installed',
                    ],
                ],
            ]));
        });

        [$exitCode, $output] = runCommand($this, 'tool:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(
            fn (Request $request): bool => $request->method() === 'GET' && str_contains($request->url(), '/api/me'),
        );

        expect($toolRequestUrl)
            ->toContain('/api/tools')
            ->and($toolRequestData)
            ->not->toHaveKey('self')->and((string) $toolRequestUrl)
            ->not->toContain('self=1');

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['tools'][0]['node'])
            ->toBe('caller');

        remove_tool_list_config_store($store);
    });

    it('uses all visible nodes when --all is provided', function (): void {
        $store = create_tool_list_config_store('tests/.tmp-tool-list-all-config.json', defaultNode: 'default-app');

        fakeGateway(fakeSuccessEnvelope([
            'tools' => [
                ['name' => 'composer', 'node' => 'app-1'],
                ['name' => 'php', 'node' => 'app-2'],
            ],
        ]));

        [$exitCode] = runCommand($this, 'tool:list', [
            '--all' => true,
            '--json' => true,
        ]);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return str_contains($url, '/api/tools') && ! str_contains($url, 'node=') && ! str_contains($url, 'self=1');
        });

        expect($exitCode)->toBe(0);

        remove_tool_list_config_store($store);
    });

    it('renders human output with the Prompts-backed property-list component', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'tools' => [
                [
                    'name' => 'composer',
                    'node' => 'app-1',
                    'expected_state' => 'installed',
                    'observed_state' => null,
                    'observed_version' => null,
                    'version' => null,
                    'managed' => true,
                    'endpoints' => [],
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:list', ['--node' => 'app-1']);
        $plain = strip_tool_list_ansi($output);

        expect($exitCode)
            ->toBe(0)
            ->and($plain)
            ->toContain('┌ Node: app-1')
            ->and($plain)
            ->toContain('│ composer')
            ->and($plain)
            ->toContain('│   Expected: installed')
            ->and($plain)
            ->toContain('│   Managed: yes')
            ->and($plain)
            ->toContain('│   Version: —')
            ->and($plain)
            ->toContain('└')
            ->and($plain)
            ->not->toContain('  composer')->and($plain)
            ->not->toContain('TOOL');
    });

    it('passes through gateway error codes from HTTP failures', function (): void {
        fakeGateway(fakeErrorEnvelope('authorization_failed', 'This node is not authorized to manage tools.'), 403);

        [$exitCode, $output] = runCommand($this, 'tool:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('authorization_failed');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('Network is unreachable');

        [$exitCode, $output] = runCommand($this, 'tool:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});

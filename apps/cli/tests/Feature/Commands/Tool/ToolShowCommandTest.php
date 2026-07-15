<?php

declare(strict_types=1);

use App\Services\OrbitConfigStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('tool:show', function (): void {
    it('returns a canonical success envelope in JSON mode and forwards filters', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'tool' => [
                'name' => 'composer',
                'node' => 'app-1',
                'expected_state' => 'installed',
                'observed_state' => null,
                'observed_version' => null,
                'version' => '2.8',
                'managed' => true,
                'endpoints' => [],
            ],
        ], ['live' => false]));

        [$exitCode, $output] = runCommand($this, 'tool:show', [
            'tool' => 'composer',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return (
                $request->method() === 'GET'
                && str_contains($url, '/api/tools/composer')
                && str_contains($url, 'node=app-1')
                && ! str_contains($url, 'live=')
            );
        });

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['tool'])
            ->toBe([
                'name' => 'composer',
                'node' => 'app-1',
                'expected_state' => 'installed',
                'observed_state' => null,
                'observed_version' => null,
                'version' => '2.8',
                'managed' => true,
                'endpoints' => [],
            ]);
    });

    it('forwards the live query flag when requested', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'tool' => [
                'name' => 'composer',
                'node' => 'app-1',
                'expected_state' => 'installed',
                'observed_state' => 'installed',
                'observed_version' => '2.8.1',
            ],
        ], ['live' => true]));

        [$exitCode, $output] = runCommand($this, 'tool:show', [
            'tool' => 'composer',
            '--node' => 'app-1',
            '--live' => true,
            '--json' => true,
        ]);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return (
                $request->method() === 'GET'
                && str_contains($url, '/api/tools/composer')
                && str_contains($url, 'live=1')
                && ! $request->hasHeader('X-Orbit-Node-Transport-Preference')
            );
        });

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['tool']['observed_version'])
            ->toBe('2.8.1');
    });

    it('renders human output as a tool detail view', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'tool' => [
                'name' => 'composer',
                'node' => 'app-1',
                'expected_state' => 'installed',
                'observed_state' => null,
                'observed_version' => null,
                'version' => '2.8',
                'managed' => true,
                'endpoints' => [],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:show', [
            'tool' => 'composer',
            '--node' => 'app-1',
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Tool: composer')
            ->and($output)
            ->toContain('Node')
            ->and($output)
            ->toContain('app-1')
            ->and($output)
            ->toContain('Expected')
            ->and($output)
            ->toContain('installed')
            ->and($output)
            ->not->toContain('Observed');
    });

    it('uses the local default node when no target option is provided', function (): void {
        $store = new OrbitConfigStore(overridePath: base_path('tests/.tmp-tool-show-config.json'));
        @unlink($store->path());
        $store->save(['defaults' => ['node' => 'default-app', 'profile' => null]]);
        app()->instance(OrbitConfigStore::class, $store);

        fakeGateway(fakeSuccessEnvelope([
            'tool' => [
                'name' => 'composer',
                'node' => 'default-app',
                'expected_state' => 'installed',
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:show', [
            'tool' => 'composer',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return str_contains($url, '/api/tools/composer') && str_contains($url, 'node=default-app');
        });

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['tool']['node'])->toBe('default-app');

        @unlink($store->path());
    });

    it('fails validation before opening the gateway request when tool is missing', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'tool:show', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('tool');
    });

    it('passes through gateway error codes from HTTP failures', function (): void {
        fakeGateway(fakeErrorEnvelope('tool.not_found', "Tool 'composer' not found on node 'app-1'.", [
            'tool' => 'composer',
            'node' => 'app-1',
        ]), 404);

        [$exitCode, $output] = runCommand($this, 'tool:show', [
            'tool' => 'composer',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('tool.not_found');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('No route to host');

        [$exitCode, $output] = runCommand($this, 'tool:show', [
            'tool' => 'composer',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});

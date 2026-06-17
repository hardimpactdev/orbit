<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('metrics commands', function (): void {
    it('enables metrics by adding the metrics role to the selected node', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'node' => 'app-1',
            'assignment' => ['role' => 'metrics', 'status' => 'active'],
        ]));

        [$exitCode] = runCommand($this, 'metrics:enable', [
            '--node' => 'app-1',
            '--json' => true,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/api/nodes/app-1/roles')
            && $request['role'] === 'metrics'
            && $request['settings'] === []);

        expect($exitCode)->toBe(0);
    });

    it('requires --node before enabling metrics', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'metrics:enable', [
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('node');
    });

    it('requires --force before disabling metrics', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'metrics:disable', [
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('force');
    });

    it('disables metrics through the node role API when force is supplied', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'node' => 'app-1',
            'role' => 'metrics',
        ]));

        [$exitCode] = runCommand($this, 'metrics:disable', [
            '--node' => 'app-1',
            '--force' => true,
            '--json' => true,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/api/nodes/app-1/roles/metrics')
            && $request['force'] === true
            && $request['purge_data'] === false);

        expect($exitCode)->toBe(0);
    });

    it('reads metrics status from the gateway', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'metrics' => [
                [
                    'node' => 'metrics-1',
                    'url' => 'https://metrics.orbit',
                    'processes' => [
                        ['name' => 'prometheus', 'runtime' => 'docker-swarm'],
                        ['name' => 'grafana', 'runtime' => 'docker-swarm'],
                        ['name' => 'node-exporter', 'runtime' => 'systemd'],
                    ],
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'metrics:status', [
            '--node' => 'metrics-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains(urldecode($request->url()), '/api/metrics/status')
            && str_contains(urldecode($request->url()), 'node=metrics-1'));

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['metrics'][0]['node'])->toBe('metrics-1');
    });

    it('reads Grafana credentials from the gateway', function (): void {
        fakeGateway(fakeMetricsCredentialsEnvelope('metrics-1'));

        [$exitCode, $output] = runCommand($this, 'metrics:credentials', [
            '--node' => 'metrics-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains(urldecode($request->url()), '/api/metrics/credentials')
            && str_contains(urldecode($request->url()), 'node=metrics-1'));

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['credentials']['url'])->toBe('https://metrics.orbit')
            ->and($decoded['success']['data']['credentials']['admin_password'])->toBe('secret-password');
    });

    it('resets Grafana credentials through the gateway when requested', function (): void {
        fakeGateway(fakeMetricsCredentialsEnvelope('metrics-1', password: 'new-secret-password'));

        [$exitCode, $output] = runCommand($this, 'metrics:credentials', [
            '--node' => 'metrics-1',
            '--reset' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/api/metrics/credentials/reset')
            && $request['node'] === 'metrics-1');

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['credentials']['admin_password'])->toBe('new-secret-password');
    });
});

/**
 * @return array<string, mixed>
 */
function fakeMetricsCredentialsEnvelope(string $node, string $password = 'secret-password'): array
{
    return fakeSuccessEnvelope([
        'credentials' => [
            'node' => $node,
            'url' => 'https://metrics.orbit',
            'admin_user' => 'admin',
            'admin_password' => $password,
        ],
    ], [
        'process' => 'grafana',
    ]);
}

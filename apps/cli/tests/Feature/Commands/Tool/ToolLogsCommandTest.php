<?php

declare(strict_types=1);

use App\Services\OrbitConfigStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('tool:logs', function (): void {
    it('returns bounded logs as a canonical success envelope and forwards filters', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'logs' => [
                'lines' => [
                    ['message' => 'supervisor started'],
                    ['message' => 'supervisor running'],
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:logs', [
            'tool' => 'supervisor',
            '--node' => 'app-1',
            '--lines' => 2,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'GET'
                && str_contains($url, '/api/tools/supervisor/logs')
                && str_contains($url, 'node=app-1')
                && str_contains($url, 'lines=2');
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['logs']['lines'][0]['message'])->toBe('supervisor started');
    });

    it('forwards explicit instance selectors for bounded logs', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'logs' => [
                'tool' => 'mysql',
                'node' => 'database-1',
                'process' => 'mysql8',
                'lines' => [
                    ['message' => 'mysql started'],
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:logs', [
            'tool' => 'mysql',
            '--node' => 'database-1',
            '--instance' => 'mysql:8',
            '--lines' => 1,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'GET'
                && str_contains($url, '/api/tools/mysql/logs')
                && str_contains($url, 'node=database-1')
                && str_contains($url, 'instance=mysql:8')
                && str_contains($url, 'lines=1');
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['logs']['process'])->toBe('mysql8');
    });

    it('renders bounded log lines for human output', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'logs' => [
                'lines' => [
                    ['message' => 'supervisor started'],
                    ['message' => 'supervisor running'],
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:logs', [
            'tool' => 'supervisor',
            '--node' => 'app-1',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('supervisor started')
            ->and($output)->toContain('supervisor running');
    });

    it('prints a finite human fallback when no log lines are returned', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'logs' => [
                'lines' => [],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:logs', [
            'tool' => 'supervisor',
            '--node' => 'app-1',
        ]);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('No log lines found.');
    });

    it('uses the local default node when no target option is provided', function (): void {
        $store = new OrbitConfigStore(overridePath: base_path('tests/.tmp-tool-logs-config.json'));
        @unlink($store->path());
        $store->save(['defaults' => ['node' => 'default-app', 'profile' => null]]);
        app()->instance(OrbitConfigStore::class, $store);

        fakeGateway(fakeSuccessEnvelope([
            'logs' => [
                'lines' => [
                    ['message' => 'default node log'],
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'tool:logs', [
            'tool' => 'supervisor',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return str_contains($url, '/api/tools/supervisor/logs')
                && str_contains($url, 'node=default-app')
                && str_contains($url, 'lines=100');
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['logs']['lines'][0]['message'])->toBe('default node log');

        @unlink($store->path());
    });

    it('fails validation before opening the gateway request when tool is missing', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'tool:logs', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('tool');
    });

    it('fails validation before opening the gateway request when lines is invalid', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'tool:logs', [
            'tool' => 'supervisor',
            '--lines' => 0,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('lines');
    });

    it('rejects JSON follow mode before opening the gateway stream', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'tool:logs', [
            'tool' => 'supervisor',
            '--follow' => true,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('json');
    });

    it('passes through gateway error codes from HTTP failures', function (): void {
        fakeGateway(fakeErrorEnvelope('tool.unsupported_action', "Tool 'caddy' does not support logs.", ['tool' => 'caddy', 'action' => 'logs']), 400);

        [$exitCode, $output] = runCommand($this, 'tool:logs', [
            'tool' => 'caddy',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('tool.unsupported_action');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('No route to host');

        [$exitCode, $output] = runCommand($this, 'tool:logs', [
            'tool' => 'supervisor',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});

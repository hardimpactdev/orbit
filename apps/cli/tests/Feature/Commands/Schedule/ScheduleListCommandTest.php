<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('schedule:list', function (): void {
    it('returns a canonical success envelope in JSON mode and forwards filters', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'schedules' => [
                [
                    'name' => 'laravel-scheduler',
                    'scope' => 'instance',
                    'target' => ['type' => 'instance', 'name' => 'docs.production', 'node' => 'app-1'],
                    'interval' => 'every minute',
                    'timezone' => 'UTC',
                    'execution' => ['type' => 'command', 'value' => 'php artisan schedule:run'],
                    'enabled' => true,
                    'status' => 'expected',
                    'last_run' => ['status' => 'completed'],
                ],
            ],
        ], ['instance' => 'docs.production', 'node' => null, 'count' => 1]));

        [$exitCode, $output] = runCommand($this, 'schedule:list', [
            '--instance' => 'docs.production',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return (
                $request->method() === 'GET'
                && str_contains($url, '/api/schedules')
                && str_contains($url, 'instance=docs.production')
            );
        });

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['schedules'][0]['name'])
            ->toBe('laravel-scheduler')
            ->and($decoded['success']['meta']['count'])
            ->toBe(1);
    });

    it('renders human output as a table with uppercase headers and schedule cells', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'schedules' => [
                [
                    'name' => 'laravel-scheduler',
                    'scope' => 'instance',
                    'target' => ['type' => 'instance', 'name' => 'docs.production', 'node' => 'app-1'],
                    'interval' => 'every minute',
                    'execution' => ['type' => 'command', 'value' => 'php artisan schedule:run'],
                    'status' => 'expected',
                    'last_run' => ['status' => 'completed', 'finished_at' => '2026-06-17T10:00:00+00:00'],
                ],
                [
                    'name' => 'prune',
                    'scope' => 'orbit',
                    'target' => ['type' => 'orbit', 'name' => 'orbit', 'node' => 'gateway-1'],
                    'interval' => 'daily',
                    'execution' => ['type' => 'script', 'value' => 'prune.sh'],
                    'status' => 'expected',
                    'last_run' => null,
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'schedule:list', ['--instance' => 'docs.production']);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('NAME')
            ->and($output)
            ->toContain('SCOPE')
            ->and($output)
            ->toContain('TARGET')
            ->and($output)
            ->toContain('NODE')
            ->and($output)
            ->toContain('INTERVAL')
            ->and($output)
            ->toContain('EXECUTION')
            ->and($output)
            ->toContain('LAST RUN')
            ->and($output)
            ->toContain('STATUS')
            ->and($output)
            ->toContain('laravel-scheduler')
            ->and($output)
            ->toContain('app-1')
            ->and($output)
            ->toContain('every minute')
            ->and($output)
            ->toContain('command: php artisan schedule:run')
            ->and($output)
            ->toContain('completed')
            ->and($output)
            ->toContain('prune')
            ->and($output)
            ->toContain('—')
            ->and($output)
            ->not->toContain('schedules: [')->and($output)
            ->not->toContain('"execution"');
    });

    it('renders a scope-aware empty state when filtered with no matches', function (): void {
        fakeGateway(fakeSuccessEnvelope(['schedules' => []], [
            'instance' => 'docs.production',
            'node' => null,
            'count' => 0,
        ]));

        [$exitCode, $output] = runCommand($this, 'schedule:list', ['--instance' => 'docs.production']);

        expect($exitCode)->toBe(0)->and($output)->toBe('No schedules found for instance docs.production.');
    });

    it('renders a plain empty state when unfiltered with no schedules', function (): void {
        fakeGateway(fakeSuccessEnvelope(['schedules' => []], ['instance' => null, 'node' => null, 'count' => 0]));

        [$exitCode, $output] = runCommand($this, 'schedule:list');

        expect($exitCode)->toBe(0)->and($output)->toBe('No schedules found.');
    });

    it('fails validation before opening the gateway request when app and node filters are combined', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'schedule:list', [
            '--instance' => 'docs.production',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['fields'])
            ->toBe(['instance', 'node']);
    });

    it('surfaces gateway_unavailable on non-envelope gateway HTTP errors', function (): void {
        fakeGateway(['message' => 'Bad gateway'], 502);

        [$exitCode, $output] = runCommand($this, 'schedule:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('gateway_unavailable');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('No route to host');

        [$exitCode, $output] = runCommand($this, 'schedule:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});

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
                    'scope' => 'app',
                    'target' => ['type' => 'app', 'name' => 'docs', 'node' => 'app-1'],
                    'interval' => 'every minute',
                    'timezone' => 'UTC',
                    'execution' => ['type' => 'command', 'value' => 'php artisan schedule:run'],
                    'enabled' => true,
                    'status' => 'expected',
                    'last_run' => ['status' => 'completed'],
                ],
            ],
        ], ['app' => 'docs', 'node' => null, 'count' => 1]));

        [$exitCode, $output] = runCommand($this, 'schedule:list', [
            '--app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'GET'
                && str_contains($url, '/api/schedules')
                && str_contains($url, 'app=docs');
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['schedules'][0]['name'])->toBe('laravel-scheduler')
            ->and($decoded['success']['meta']['count'])->toBe(1);
    });

    it('renders human output containing schedule fields', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'schedules' => [
                ['name' => 'laravel-scheduler', 'scope' => 'app'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'schedule:list', ['--app' => 'docs']);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('schedules')
            ->and($output)->toContain('laravel-scheduler');
    });

    it('fails validation before opening the gateway request when app and node filters are combined', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'schedule:list', [
            '--app' => 'docs',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['fields'])->toBe(['app', 'node']);
    });

    it('surfaces gateway_unavailable on non-envelope gateway HTTP errors', function (): void {
        fakeGateway(['message' => 'Bad gateway'], 502);

        [$exitCode, $output] = runCommand($this, 'schedule:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unavailable');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('No route to host');

        [$exitCode, $output] = runCommand($this, 'schedule:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});

<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('AnalyticsUpdateCommand', function (): void {
    it('posts Plausible version updates to the gateway analytics endpoint', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'analytics' => [
                'node' => 'analytics-1',
                'process' => 'plausible',
                'previous_version' => '3.2.1',
                'version' => '3.2.2',
                'status' => 'updated',
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'analytics:update', [
            '--version' => '3.2.2',
            '--node' => 'analytics-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/analytics/update'
            && $request->data() === [
                'version' => '3.2.2',
                'node' => 'analytics-1',
            ]);

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['analytics']['version'])->toBe('3.2.2')
            ->and($decoded['success']['data']['analytics']['process'])->toBe('plausible');
    });

    it('omits node from the gateway payload when the analytics node is not constrained', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'analytics' => [
                'node' => 'analytics-1',
                'process' => 'plausible',
                'previous_version' => '3.2.1',
                'version' => '3.2.2',
                'status' => 'updated',
            ],
        ]));

        [$exitCode] = runCommand($this, 'analytics:update', [
            '--version' => '3.2.2',
            '--json' => true,
        ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://gateway.test/api/analytics/update'
            && $request->data() === [
                'version' => '3.2.2',
            ]);

        expect($exitCode)->toBe(0);
    });

    it('requires a version before sending gateway requests', function (): void {
        fakeGateway(fakeSuccessEnvelope());

        [$exitCode, $output] = runCommand($this, 'analytics:update', [
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])->toBe('version');
    });

    it('maps gateway failures into canonical CLI failures', function (): void {
        fakeGateway(fakeErrorEnvelope(
            'analytics.prerequisite_failed',
            'No active analytics node is available.',
            ['version' => '3.2.2'],
        ), 422);

        [$exitCode, $output] = runCommand($this, 'analytics:update', [
            '--version' => '3.2.2',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('analytics.prerequisite_failed')
            ->and($decoded['error']['meta']['version'])->toBe('3.2.2');
    });
});

<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_contains($request->url(), '/api/apps')
            && str_contains($request->url(), 'node=app-1')
            && ! str_contains($request->url(), 'environment='));

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['apps'][0]['name'])->toBe('orbit-docs');
    });

    it('does not expose the retired environment filter', function (): void {
        $command = app(Kernel::class)->all()['app:list'];

        expect($command->getDefinition()->hasOption('environment'))->toBeFalse();
    });

    it('renders human output containing app fields', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'apps' => [
                ['name' => 'orbit-docs', 'node' => 'app-1'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:list');

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('apps');
    });

    it('surfaces gateway_unavailable on gateway HTTP errors', function (): void {
        fakeGateway(['message' => 'Bad gateway'], 502);

        [$exitCode, $output] = runCommand($this, 'app:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unavailable');
    });

    it('preserves structured gateway authorization failures', function (): void {
        fakeGateway(fakeErrorEnvelope('authorization_failed', 'Missing app read permission.', [
            'missing_permission' => 'app:read',
        ]), 403);

        [$exitCode, $output] = runCommand($this, 'app:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('authorization_failed')
            ->and($decoded['error']['meta']['missing_permission'])->toBe('app:read');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('Operation timed out');

        [$exitCode, $output] = runCommand($this, 'app:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});

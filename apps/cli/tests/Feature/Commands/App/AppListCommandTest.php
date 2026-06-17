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

    it('renders human output grouped by node as tables with workspace child rows', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'apps' => [
                [
                    'name' => 'docs',
                    'node' => 'app-1',
                    'url' => 'https://docs.test',
                    'workspaces' => [
                        ['name' => 'feature-a', 'url' => 'https://feature-a.docs.test', 'lifecycle_status' => 'active'],
                        ['name' => 'feature-b', 'url' => 'https://feature-b.docs.test', 'lifecycle_status' => 'setting_up'],
                    ],
                ],
                [
                    'name' => 'blog',
                    'node' => 'app-1',
                    'url' => 'https://blog.test',
                    'workspaces' => [],
                ],
                [
                    'name' => 'api',
                    'node' => 'app-2',
                    'url' => 'https://api.test',
                    'workspaces' => [],
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:list');

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Node: app-1')
            ->and($output)->toContain('Node: app-2')
            ->and($output)->toContain('NAME')
            ->and($output)->toContain('URL')
            ->and($output)->toContain('STATUS')
            ->and($output)->toContain('docs')
            ->and($output)->toContain('blog')
            ->and($output)->toContain('api')
            ->and($output)->toContain('expected')
            ->and($output)->toContain('├─ feature-a')
            ->and($output)->toContain('└─ feature-b')
            ->and($output)->toContain('active')
            ->and($output)->toContain('setting_up')
            ->and($output)->not->toContain('apps: [')
            ->and($output)->not->toContain('"lifecycle_status"');
    });

    it('renders human empty output when no apps are visible', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'apps' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'app:list');

        expect($exitCode)->toBe(0)
            ->and($output)->toBe('No apps found.');
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

<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('workspace:list', function (): void {
    it('returns a canonical success envelope in JSON mode and forwards filters', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'workspaces' => [
                [
                    'name' => 'feature-docs',
                    'app' => 'docs',
                    'node' => 'app-1',
                    'url' => 'https://feature-docs.docs.test',
                    'lifecycle_status' => 'expected',
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'workspace:list', [
            '--app' => 'docs',
            '--node' => 'app-1',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'GET'
                && str_contains($url, '/api/workspaces')
                && str_contains($url, 'app=docs')
                && str_contains($url, 'node=app-1');
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['workspaces'][0]['name'])->toBe('feature-docs');
    });

    it('renders human output grouped by node then app as tables', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'workspaces' => [
                [
                    'name' => 'feature-docs',
                    'app' => 'docs',
                    'node' => 'app-1',
                    'url' => 'https://feature-docs.docs.test',
                    'lifecycle_status' => 'expected',
                ],
                [
                    'name' => 'main',
                    'app' => 'docs',
                    'node' => 'app-1',
                    'url' => 'https://docs.test',
                    'lifecycle_status' => 'expected',
                ],
                [
                    'name' => 'main',
                    'app' => 'orbit',
                    'node' => 'app-2',
                    'url' => 'https://main.orbit.test',
                    'lifecycle_status' => 'setup-pending',
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'workspace:list');

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Node: app-1')
            ->and($output)->toContain('App: docs')
            ->and($output)->toContain('Node: app-2')
            ->and($output)->toContain('App: orbit')
            ->and($output)->toContain('WORKSPACE')
            ->and($output)->toContain('URL')
            ->and($output)->toContain('LIFECYCLE STATUS')
            ->and($output)->toContain('feature-docs')
            ->and($output)->toContain('https://docs.test')
            ->and($output)->toContain('setup-pending')
            ->and($output)->not->toContain('workspaces: [')
            ->and($output)->not->toContain('"lifecycle_status"');
    });

    it('renders missing workspace cells as an em dash', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'workspaces' => [
                ['name' => 'feature-docs', 'app' => 'docs', 'node' => 'app-1'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'workspace:list');

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('—');
    });

    it('renders human empty output when no workspaces are visible', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'workspaces' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'workspace:list');

        expect($exitCode)->toBe(0)
            ->and($output)->toBe('No workspaces found.');
    });

    it('surfaces gateway_unavailable on gateway HTTP errors', function (): void {
        fakeGateway(fakeErrorEnvelope('internal_error', 'Server failure.'), 500);

        [$exitCode, $output] = runCommand($this, 'workspace:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unavailable');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('Network is unreachable');

        [$exitCode, $output] = runCommand($this, 'workspace:list', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});

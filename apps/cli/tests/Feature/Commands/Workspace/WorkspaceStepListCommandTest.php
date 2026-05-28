<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

describe('workspace step read commands', function (): void {
    it('returns setup steps in JSON mode and forwards the app filter', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'steps' => [
                [
                    'id' => 10,
                    'app' => 'docs',
                    'phase' => 'setup',
                    'order' => 1,
                    'command' => 'composer install',
                    'timeout_seconds' => 600,
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'workspace-setup-step:list', [
            '--app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'GET'
                && str_contains($url, '/api/workspaces/steps/setup')
                && str_contains($url, 'app=docs');
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['steps'][0]['phase'])->toBe('setup');
    });

    it('returns teardown steps in JSON mode and forwards the app filter', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'steps' => [
                [
                    'id' => 11,
                    'app' => 'docs',
                    'phase' => 'teardown',
                    'order' => 1,
                    'command' => 'dropdb docs',
                    'timeout_seconds' => 600,
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'workspace-teardown-step:list', [
            '--app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'GET'
                && str_contains($url, '/api/workspaces/steps/teardown')
                && str_contains($url, 'app=docs');
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['steps'][0]['phase'])->toBe('teardown');
    });

    it('resolves the app from a local orbit marker before falling back to path lookup', function (): void {
        $root = base_path('tests/.tmp-workspace-step-marker');
        $workspacePath = "{$root}/worktrees/feature-docs";
        File::ensureDirectoryExists("{$root}/.orbit");
        File::ensureDirectoryExists($workspacePath);
        File::put("{$root}/.orbit/config", json_encode(['app' => 'docs'], JSON_THROW_ON_ERROR));

        $previousHostCwd = getenv('ORBIT_HOST_CWD');
        putenv("ORBIT_HOST_CWD={$workspacePath}");

        try {
            fakeGateway(fakeSuccessEnvelope(['steps' => []]));

            [$exitCode] = runCommand($this, 'workspace-setup-step:list', ['--json' => true]);
        } finally {
            restoreHostCwd($previousHostCwd);
            File::deleteDirectory($root);
        }

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'GET'
                && str_contains($url, '/api/workspaces/steps/setup')
                && str_contains($url, 'app=docs')
                && ! str_contains($url, 'path=');
        });

        expect($exitCode)->toBe(0);
    });

    it('falls back to host cwd path lookup when no app marker is available', function (): void {
        $previousHostCwd = getenv('ORBIT_HOST_CWD');
        putenv('ORBIT_HOST_CWD=/srv/docs/.worktrees/feature-docs');

        try {
            fakeGateway(fakeSuccessEnvelope(['steps' => []]));

            [$exitCode] = runCommand($this, 'workspace-teardown-step:list', ['--json' => true]);
        } finally {
            restoreHostCwd($previousHostCwd);
        }

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'GET'
                && str_contains($url, '/api/workspaces/steps/teardown')
                && str_contains($url, 'path=/srv/docs/.worktrees/feature-docs');
        });

        expect($exitCode)->toBe(0);
    });

    it('renders human output containing step fields', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'steps' => [
                ['id' => 10, 'app' => 'docs', 'phase' => 'setup', 'command' => 'composer install'],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'workspace-setup-step:list', ['--app' => 'docs']);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('steps');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('No route to host');

        [$exitCode, $output] = runCommand($this, 'workspace-setup-step:list', [
            '--app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});

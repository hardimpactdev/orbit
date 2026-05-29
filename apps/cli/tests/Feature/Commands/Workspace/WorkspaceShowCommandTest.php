<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('workspace:show', function (): void {
    it('returns a canonical success envelope in JSON mode and forwards the workspace path', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'workspace' => [
                'name' => 'feature-docs',
                'app' => 'docs',
                'url' => 'https://feature-docs.docs.test',
            ],
        ], ['registry_only' => true]));

        [$exitCode, $output] = runCommand($this, 'workspace:show', [
            'name' => 'feature-docs',
            '--app' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'GET'
                && str_contains($url, '/api/workspaces/feature-docs')
                && str_contains($url, 'app=docs');
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['workspace']['name'])->toBe('feature-docs')
            ->and($decoded['success']['meta']['registry_only'])->toBeTrue();
    });

    it('uses the host cwd path resolver when no workspace name is supplied', function (): void {
        $previousHostCwd = getenv('ORBIT_HOST_CWD');
        putenv('ORBIT_HOST_CWD=/srv/docs/.worktrees/feature-docs');

        try {
            fakeGateway(fakeSuccessEnvelope([
                'workspace' => ['name' => 'feature-docs', 'app' => 'docs'],
            ]));

            [$exitCode, $output] = runCommand($this, 'workspace:show', [
                '--app' => 'docs',
                '--json' => true,
            ]);
        } finally {
            restoreHostCwd($previousHostCwd);
        }

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return $request->method() === 'GET'
                && str_contains($url, '/api/workspaces/resolve-by-path')
                && str_contains($url, 'app=docs')
                && str_contains($url, 'path=/srv/docs/.worktrees/feature-docs');
        });

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['workspace']['name'])->toBe('feature-docs');
    });

    it('renders human output containing workspace fields', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'workspace' => [
                'name' => 'feature-docs',
                'app' => 'docs',
                'branch' => 'feature/docs',
                'path' => '/srv/docs/.worktrees/feature-docs',
                'url' => 'https://feature-docs.docs.test',
                'node' => ['name' => 'app-dev-1', 'host' => '192.0.2.10'],
                'agent_ide' => ['adapter' => 'opencode'],
                'runtime_expectations' => [
                    'php_version' => '8.5',
                    'php_version_inherited_from' => 'app',
                    'runtime_container' => 'orbit-docs-feature-docs',
                    'hostname' => 'feature-docs.docs.test',
                ],
                'inherited_processes' => [['name' => 'vite']],
                'route' => ['host' => 'feature-docs.docs.test', 'kind' => 'workspace', 'owner' => 'workspace'],
                'latest_setup_run' => null,
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'workspace:show', ['name' => 'feature-docs']);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Workspace: feature-docs')
            ->and($output)->toContain('App')
            ->and($output)->toContain('docs')
            ->and($output)->toContain('Processes')
            ->and($output)->toContain('vite');
    });

    it('surfaces gateway error envelopes without replacing the error code', function (): void {
        fakeGateway(fakeErrorEnvelope('workspace.not_found', 'Workspace not found.'), 404);

        [$exitCode, $output] = runCommand($this, 'workspace:show', [
            'name' => 'missing-workspace',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('workspace.not_found');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('No route to host');

        [$exitCode, $output] = runCommand($this, 'workspace:show', [
            'name' => 'feature-docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});

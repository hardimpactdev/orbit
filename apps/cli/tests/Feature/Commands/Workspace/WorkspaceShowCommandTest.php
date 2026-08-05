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
            '--instance' => 'docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return (
                $request->method() === 'GET'
                && str_contains($url, '/api/workspaces/feature-docs')
                && str_contains($url, 'instance=docs')
            );
        });

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['workspace']['name'])
            ->toBe('feature-docs')
            ->and($decoded['success']['meta']['registry_only'])
            ->toBeTrue();
    });

    it('uses the host cwd path resolver when no workspace name is supplied', function (): void {
        $previousHostCwd = getenv('ORBIT_HOST_CWD');
        putenv('ORBIT_HOST_CWD=/srv/docs/.worktrees/feature-docs');

        try {
            fakeGateway(fakeSuccessEnvelope([
                'workspace' => ['name' => 'feature-docs', 'app' => 'docs'],
            ]));

            [$exitCode, $output] = runCommand($this, 'workspace:show', [
                '--instance' => 'docs',
                '--json' => true,
            ]);
        } finally {
            restoreHostCwd($previousHostCwd);
        }

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return (
                $request->method() === 'GET'
                && str_contains($url, '/api/workspaces/resolve-by-path')
                && str_contains($url, 'instance=docs')
                && str_contains($url, 'path=/srv/docs/.worktrees/feature-docs')
            );
        });

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['workspace']['name'])->toBe('feature-docs');
    });

    it('renders human output with the contracted layout', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'workspace' => [
                'name' => 'feature-docs',
                'app' => 'docs',
                'node' => 'app-1',
                'path' => '/home/orbit/apps/docs/.worktrees/feature-docs',
                'url' => 'https://feature-docs.docs.test',
                'php_version' => '8.5',
                'php_inherited' => true,
                'adopted' => false,
                'lifecycle_status' => 'expected',
            ],
            'node' => ['name' => 'app-1', 'host' => '1.2.3.4'],
            'inherited_processes' => [['name' => 'vite'], ['name' => 'queue']],
        ], ['registry_only' => true]));

        [$exitCode, $output] = runCommand($this, 'workspace:show', ['name' => 'feature-docs']);

        expect($exitCode)
            ->toBe(0)
            // title
            ->and($output)
            ->toContain('Workspace: feature-docs.docs')
            // URL line
            ->and($output)
            ->toContain('URL')
            ->and($output)
            ->toContain('https://feature-docs.docs.test')
            // Node line with host
            ->and($output)
            ->toContain('Node')
            ->and($output)
            ->toContain('app-1 (1.2.3.4)')
            // Path
            ->and($output)
            ->toContain('Path')
            ->and($output)
            ->toContain('/home/orbit/apps/docs/.worktrees/feature-docs')
            // PHP
            ->and($output)
            ->toContain('PHP')
            ->and($output)
            ->toContain('8.5')
            // Processes
            ->and($output)
            ->toContain('Processes')
            ->and($output)
            ->toContain('vite')
            ->and($output)
            ->toContain('queue')
            // absent legacy fields
            ->and($output)
            ->not->toContain('Branch')->and($output)
            ->not->toContain('Route')->and($output)
            ->not->toContain('Runtime container')->and($output)
            ->not->toContain('Hostname')->and($output)
            ->not->toContain('Status')->and($output)
            ->not->toContain('Adopted')->and($output)
            ->not->toContain('Latest setup')->and($output)
            ->not->toContain('inherited from');
    });

    it('surfaces gateway error envelopes without replacing the error code', function (): void {
        fakeGateway(fakeErrorEnvelope('workspace.not_found', 'Workspace not found.'), 404);

        [$exitCode, $output] = runCommand($this, 'workspace:show', [
            'name' => 'missing-workspace',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('workspace.not_found');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('No route to host');

        [$exitCode, $output] = runCommand($this, 'workspace:show', [
            'name' => 'feature-docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});

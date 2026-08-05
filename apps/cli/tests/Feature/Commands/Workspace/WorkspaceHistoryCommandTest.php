<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('workspace:history', function (): void {
    it('returns a canonical success envelope in JSON mode and forwards filters', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'runs' => [
                [
                    'id' => 12,
                    'workspace' => 'feature-docs',
                    'app' => 'docs',
                    'action' => 'setup',
                    'status' => 'completed',
                ],
            ],
        ], [
            'pagination' => [
                'total' => 1,
                'limit' => 20,
                'since' => '2026-05-01T00:00:00+00:00',
                'until' => '2026-05-02T00:00:00+00:00',
                'limit_capped' => false,
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'workspace:history', [
            'name' => 'feature-docs',
            '--instance' => 'docs',
            '--limit' => '20',
            '--since' => '2026-05-01T00:00:00+00:00',
            '--until' => '2026-05-02T00:00:00+00:00',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return (
                $request->method() === 'GET'
                && str_contains($url, '/api/workspaces/feature-docs/history')
                && str_contains($url, 'instance=docs')
                && str_contains($url, 'limit=20')
                && str_contains($url, 'since=2026-05-01T00:00:00+00:00')
                && str_contains($url, 'until=2026-05-02T00:00:00+00:00')
            );
        });

        expect($exitCode)
            ->toBe(0)
            ->and($decoded['success']['data']['runs'][0]['id'])
            ->toBe(12)
            ->and($decoded['success']['meta']['pagination']['total'])
            ->toBe(1);
    });

    it('uses the host cwd path resolver when no workspace name is supplied', function (): void {
        $previousHostCwd = getenv('ORBIT_HOST_CWD');
        putenv('ORBIT_HOST_CWD=/srv/docs/.worktrees/feature-docs');

        try {
            fakeGateway(fakeSuccessEnvelope(['runs' => []], [
                'pagination' => ['total' => 0, 'limit' => 50],
            ]));

            [$exitCode, $output] = runCommand($this, 'workspace:history', ['--json' => true]);
        } finally {
            restoreHostCwd($previousHostCwd);
        }

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(function (Request $request): bool {
            $url = urldecode($request->url());

            return (
                $request->method() === 'GET'
                && str_contains($url, '/api/workspaces/history/resolve-by-path')
                && str_contains($url, 'path=/srv/docs/.worktrees/feature-docs')
            );
        });

        expect($exitCode)->toBe(0)->and($decoded['success']['data']['runs'])->toBe([]);
    });

    it('renders human output as a run-history table with header and columns', function (): void {
        $completedRunStartedAt = now()->subMinutes(2);

        fakeGateway(fakeSuccessEnvelope([
            'runs' => [
                [
                    'id' => 12,
                    'workspace' => 'feature-docs',
                    'app' => 'ohdear',
                    'action' => 'setup',
                    'status' => 'completed',
                    'started_at' => $completedRunStartedAt->toIso8601String(),
                    'finished_at' => $completedRunStartedAt->copy()->addSeconds(45)->toIso8601String(),
                ],
                [
                    'id' => 11,
                    'workspace' => 'feature-docs',
                    'app' => 'ohdear',
                    'action' => 'setup',
                    'status' => 'running',
                    'started_at' => now()->subMinute()->toIso8601String(),
                    'finished_at' => null,
                ],
            ],
        ]));

        [$exitCode, $output] = runCommand($this, 'workspace:history', ['name' => 'feature-docs']);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain('Workspace History: ohdear/feature-docs')
            ->and($output)
            ->toContain('ID')
            ->and($output)
            ->toContain('ACTION')
            ->and($output)
            ->toContain('STATUS')
            ->and($output)
            ->toContain('STARTED')
            ->and($output)
            ->toContain('DURATION')
            ->and($output)
            ->toContain('setup')
            ->and($output)
            ->toContain('completed')
            ->and($output)
            ->toContain('45s')
            ->and($output)
            ->toContain('ago')
            ->and($output)
            ->toContain('—')
            ->and($output)
            ->not->toContain('runs: [')->and($output)
            ->not->toContain('"started_at"');
    });

    it('renders human empty output when no runs exist', function (): void {
        fakeGateway(fakeSuccessEnvelope([
            'runs' => [],
        ]));

        [$exitCode, $output] = runCommand($this, 'workspace:history', ['name' => 'feature-docs']);

        expect($exitCode)->toBe(0)->and($output)->toBe('No run history found.');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('No route to host');

        [$exitCode, $output] = runCommand($this, 'workspace:history', [
            'name' => 'feature-docs',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});

<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('workspace:log', function (): void {
    it('returns captured run detail as a canonical success envelope and requests the run log API', function (): void {
        fakeGateway(fakeWorkspaceLogEnvelope());

        [$exitCode, $output] = runCommand($this, 'workspace:log', [
            'run' => '12',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && $request->url() === 'https://gateway.test/api/workspaces/runs/12/log');

        expect($exitCode)->toBe(0)
            ->and($decoded['success']['data']['run']['id'])->toBe(12)
            ->and($decoded['success']['data']['run']['workspace'])->toBe('feature-docs')
            ->and($decoded['success']['data']['run']['steps'][0]['duration_ms'])->toBe(1000)
            ->and($decoded['success']['data']['run']['steps'][0]['stdout_truncated'])->toBeFalse()
            ->and($decoded['success']['data']['run']['steps'][1]['duration_ms'])->toBe(8200)
            ->and($decoded['success']['data']['run']['steps'][1]['stderr_truncated'])->toBeTrue()
            ->and($decoded['success']['meta']['registry_only'])->toBeTrue();
    });

    it('renders captured step output for human output', function (): void {
        fakeGateway(fakeWorkspaceLogEnvelope());

        [$exitCode, $output] = runCommand($this, 'workspace:log', ['run' => '12']);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Workspace Log Run #12  (docs/feature-docs on app-1)')
            ->and($output)->toContain('✔ Validate workspace configuration')
            ->and($output)->toContain('✘ Install dependencies (composer install)')
            ->and($output)->toContain('[EXIT CODE 1]')
            ->and($output)->toContain('STDOUT:')
            ->and($output)->toContain('> Updating dependencies')
            ->and($output)->toContain('STDERR:')
            ->and($output)->toContain('> Could not resolve dependencies [TRUNCATED]')
            ->and($output)->toContain('· Notify skipped step')
            ->and($output)->toContain('Run #12 failed (started 2026-04-30 10:00:00, duration 12.5s)');
    });

    it('fails validation before opening a gateway request when run is missing', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'workspace:log', ['--json' => true]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['message'])->toBe('Workspace run ID is required.')
            ->and($decoded['error']['meta']['field'])->toBe('run');
    });

    it('fails validation before opening a gateway request when run is not positive integer', function (string $run): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'workspace:log', [
            'run' => $run,
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('validation_failed')
            ->and($decoded['error']['message'])->toBe('Workspace run ID must be a positive integer.')
            ->and($decoded['error']['meta']['field'])->toBe('run')
            ->and($decoded['error']['meta']['value'])->toBe($run);
    })->with(['0', 'nope']);

    it('surfaces gateway run-not-found failures without collapsing the error code', function (): void {
        fakeGateway(fakeErrorEnvelope('workspace.run_not_found', 'Workspace run 999 not found.', ['id' => 999]), 404);

        [$exitCode, $output] = runCommand($this, 'workspace:log', [
            'run' => '999',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('workspace.run_not_found')
            ->and($decoded['error']['message'])->toBe('Workspace run 999 not found.')
            ->and($decoded['error']['meta']['id'])->toBe(999);
    });

    it('surfaces gateway authorization failures with stable metadata', function (): void {
        fakeGateway(fakeErrorEnvelope(
            'authorization_failed',
            "This caller is not authorized to read logs for workspace 'feature-docs'.",
            ['workspace' => 'feature-docs', 'app' => 'docs'],
        ), 403);

        [$exitCode, $output] = runCommand($this, 'workspace:log', [
            'run' => '12',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('authorization_failed')
            ->and($decoded['error']['meta']['workspace'])->toBe('feature-docs')
            ->and($decoded['error']['meta']['app'])->toBe('docs');
    });

    it('surfaces wireguard-specific gateway failures', function (): void {
        fakeGatewayDown('No route to host');

        [$exitCode, $output] = runCommand($this, 'workspace:log', [
            'run' => '12',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($decoded['error']['code'])->toBe('gateway_unreachable_wireguard');
    });
});

/**
 * @return array<string, mixed>
 */
function fakeWorkspaceLogEnvelope(): array
{
    return fakeSuccessEnvelope([
        'run' => [
            'id' => 12,
            'workspace' => 'feature-docs',
            'app' => 'docs',
            'node' => 'app-1',
            'type' => 'setup',
            'status' => 'failed',
            'started_at' => '2026-04-30T10:00:00Z',
            'finished_at' => '2026-04-30T10:00:12Z',
            'duration_ms' => 12500,
            'steps' => [
                [
                    'name' => 'Validate workspace configuration',
                    'command' => 'Validate workspace configuration',
                    'status' => 'success',
                    'exit_code' => 0,
                    'stdout' => '',
                    'stderr' => '',
                    'stdout_truncated' => false,
                    'stderr_truncated' => false,
                    'started_at' => '2026-04-30T10:00:00Z',
                    'finished_at' => '2026-04-30T10:00:01Z',
                    'duration_ms' => 1000,
                ],
                [
                    'name' => 'Install dependencies',
                    'command' => 'composer install',
                    'status' => 'failure',
                    'exit_code' => 1,
                    'stdout' => "Loading repositories\nUpdating dependencies",
                    'stderr' => 'Could not resolve dependencies [TRUNCATED]',
                    'stdout_truncated' => false,
                    'stderr_truncated' => true,
                    'started_at' => '2026-04-30T10:00:03Z',
                    'finished_at' => '2026-04-30T10:00:11Z',
                    'duration_ms' => 8200,
                ],
                [
                    'name' => 'Notify skipped step',
                    'command' => 'notify',
                    'status' => 'skipped',
                    'exit_code' => null,
                    'stdout' => '',
                    'stderr' => '',
                    'stdout_truncated' => false,
                    'stderr_truncated' => false,
                    'started_at' => null,
                    'finished_at' => null,
                    'duration_ms' => null,
                ],
            ],
        ],
    ], ['registry_only' => true]);
}

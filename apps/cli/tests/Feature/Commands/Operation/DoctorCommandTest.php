<?php

declare(strict_types=1);

use App\Services\GatewayApiClient;
use App\Services\GatewayLogStreamClient;
use App\Services\GatewayStreamClient;
use Illuminate\Support\Facades\Http;

/**
 * Strip ANSI escape sequences so panel substrings can be matched on the
 * visible text the operator sees.
 */
function stripAnsi(string $value): string
{
    return preg_replace('/\e\[[0-9;?]*[a-zA-Z]/', '', $value) ?? $value;
}

/**
 * @param  list<array<string, mixed>>  $issues
 * @param  array<string, mixed>  $scopeOverrides
 * @return array<string, mixed>
 */
function doctorVerifyReport(array $issues, array $scopeOverrides = [], string $mode = 'verify', array $actions = []): array
{
    $families = $scopeOverrides['families'] ?? ['node'];

    return [
        'healthy' => $issues === [] && $actions === [],
        'mode' => $mode,
        'scope' => array_merge([
            'families' => $families,
            'node' => 'beast',
            'role' => 'app-dev',
            'self' => false,
            'app' => null,
            'workspace' => null,
            'key' => null,
        ], $scopeOverrides),
        'summary' => [
            'issues' => count($issues),
            'fixed' => 0,
            'adopted' => 0,
            'skipped' => 0,
            'conflicts' => 0,
            'failed' => 0,
            'planned' => 0,
        ],
        'issues' => $issues,
        'actions' => $actions,
    ];
}

/**
 * @param  list<string>  $families
 */
function doctorRunCompleteStream(array $doctor, array $families = ['node']): string
{
    return gatewayProgressFrame('tree', [
        'title' => 'Running Doctor',
        'steps' => array_map(fn (string $family): array => ['key' => $family, 'label' => "Check {$family}"], $families),
    ]).gatewayProgressFrame('complete', [
        'exit_code' => 0,
        'data' => [
            'footer' => 'Doctor completed.',
            'doctor' => $doctor,
        ],
    ]);
}

/**
 * @param  list<string>  $families
 */
function doctorRunDriftStream(array $doctor, array $families = ['node']): string
{
    return gatewayProgressFrame('tree', [
        'title' => 'Running Doctor',
        'steps' => array_map(fn (string $family): array => ['key' => $family, 'label' => "Check {$family}"], $families),
    ]).gatewayProgressFrame('error', [
        'exit_code' => 1,
        'message' => 'Doctor detected drift.',
        'data' => [
            'code' => 'drift_detected',
            'message' => 'Doctor detected drift.',
            'meta' => [],
            'data' => ['doctor' => $doctor],
            'footer' => 'Doctor detected drift.',
        ],
    ]);
}

function fakeDoctorRunStream(string $body, int $status = 200): void
{
    config()->set('orbit.gateway.url', 'https://gateway.test');
    config()->set('orbit.gateway.timeout', 30);
    app()->forgetInstance(GatewayApiClient::class);
    app()->forgetInstance(GatewayLogStreamClient::class);
    app()->forgetInstance(GatewayStreamClient::class);

    Http::fake([
        'https://gateway.test/api/doctor/run' => Http::response($body, $status, ['Content-Type' => 'text/event-stream']),
    ]);
}

describe('doctor human panel', function (): void {
    it('renders a healthy result panel for a single-node verify run', function (): void {
        fakeDoctorRunStream(doctorRunCompleteStream(doctorVerifyReport([])));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--node' => 'beast',
            '--family' => ['node'],
        ]);

        $plain = stripAnsi($output);

        expect($exitCode)->toBe(0)
            ->and($plain)->toContain('D O C T O R  R E S U L T')
            ->and($plain)->toContain('Successfully performed check-up on beast')
            ->and($plain)->toContain('Node')
            ->and($plain)->toContain('OK')
            ->and($plain)->toContain('S U M M A R Y')
            ->and($plain)->toContain('No issues detected')
            ->and($plain)->not->toContain('Run doctor --fix');
    });

    it('renders a result panel with a node issue table and summary next-action line', function (): void {
        $report = doctorVerifyReport([
            [
                'family' => 'node',
                'node' => 'beast',
                'key' => 'node.wireguard_peer_missing',
                'code' => 'node.wireguard_peer_missing',
                'kind' => 'missing',
                'summary' => 'WireGuard peer for node beast is missing.',
                'detail' => [],
                'restorable' => true,
                'adoptable' => false,
            ],
        ]);

        fakeDoctorRunStream(doctorRunDriftStream($report));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--node' => 'beast',
            '--family' => ['node'],
        ]);

        $plain = stripAnsi($output);

        expect($exitCode)->toBe(1)
            ->and($plain)->toContain('D O C T O R  R E S U L T')
            ->and($plain)->toContain('1 issue detected')
            ->and($plain)->toContain('ISSUE')
            ->and($plain)->toContain('WireGuard peer for node beast is missing.')
            ->and($plain)->toContain('S U M M A R Y')
            ->and($plain)->toContain('Run doctor --fix manually or through an LLM to resolve issues')
            // node family table must not carry a NODE column.
            ->and($plain)->not->toContain('NODE');
    });

    it('renders entity columns for non-node families and an active-role category row', function (): void {
        $report = doctorVerifyReport(
            issues: [
                [
                    'family' => 'app',
                    'node' => 'beast',
                    'key' => 'app.http_error',
                    'code' => 'app.http_error',
                    'kind' => 'divergent',
                    'summary' => 'https://nckrtl.test returned a 500 error response',
                    'detail' => ['app' => 'nckrtl'],
                    'restorable' => false,
                    'adoptable' => false,
                ],
                [
                    'family' => 'workspace',
                    'node' => 'beast',
                    'key' => 'workspace.missing',
                    'code' => 'workspace.missing',
                    'kind' => 'missing',
                    'summary' => 'Workspace should exist on node but is missing',
                    'detail' => ['workspace' => 'abc123.nckrtl.test', 'app' => 'nckrtl'],
                    'restorable' => true,
                    'adoptable' => false,
                ],
                [
                    'family' => 'workspace',
                    'node' => 'beast',
                    'key' => 'workspace.extra',
                    'code' => 'workspace.extra',
                    'kind' => 'extra',
                    'summary' => 'Workspace exists on node but is not expected',
                    'detail' => ['workspace' => 'ui-redesign.hauser.test', 'app' => 'hauser'],
                    'restorable' => false,
                    'adoptable' => true,
                ],
            ],
            scopeOverrides: ['families' => ['node', 'app', 'workspace', 'process', 'proxy', 'firewall_rule', 'tool', 'schedule']],
        );

        fakeDoctorRunStream(doctorRunDriftStream($report, ['node', 'app', 'workspace', 'process', 'proxy', 'firewall_rule', 'tool', 'schedule']));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--node' => 'beast',
        ]);

        $plain = stripAnsi($output);

        expect($exitCode)->toBe(1)
            // Category labels from the catalog, role-derived order.
            ->and($plain)->toContain('Apps')
            ->and($plain)->toContain('Workspaces')
            ->and($plain)->toContain('Proxy routes')
            ->and($plain)->toContain('Firewall')
            // App family preferred columns.
            ->and($plain)->toContain('APP')
            ->and($plain)->toContain('nckrtl')
            // Workspace family preferred columns + both rows.
            ->and($plain)->toContain('WORKSPACE')
            ->and($plain)->toContain('abc123.nckrtl.test')
            ->and($plain)->toContain('ui-redesign.hauser.test')
            // Categories with no issues render OK.
            ->and($plain)->toContain('OK')
            // Total count summary, never "across N categories".
            ->and($plain)->toContain('3 issues detected')
            ->and($plain)->not->toContain('across');
    });

    it('wraps the node reboot-required guidance instead of truncating it', function (): void {
        $guidance = 'This node requires an explicit reboot to finish installed updates. '
            .'Orbit will not reboot it automatically. Reboot this server as soon as possible.';

        $report = doctorVerifyReport([
            [
                'family' => 'node',
                'node' => 'beast',
                'key' => 'node.updates_reboot_required',
                'code' => 'node.updates_reboot_required',
                'kind' => 'divergent',
                'summary' => $guidance,
                'detail' => [],
                'restorable' => false,
                'adoptable' => false,
            ],
        ]);

        fakeDoctorRunStream(doctorRunDriftStream($report));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--node' => 'beast',
            '--family' => ['node'],
        ]);

        $plain = stripAnsi($output);

        expect($exitCode)->toBe(1)
            ->and($plain)->toContain('This node requires an explicit reboot to finish installed updates.')
            ->and($plain)->toContain('Reboot this server as soon as possible.')
            // Long node summaries wrap rather than truncate with an ellipsis.
            ->and($plain)->not->toContain('…');
    });

    it('renders restore-mode action results and title', function (): void {
        $report = doctorVerifyReport(
            issues: [],
            mode: 'restore',
            actions: [
                [
                    'family' => 'node',
                    'node' => 'beast',
                    'key' => 'node.config',
                    'mode' => 'restore',
                    'status' => 'completed',
                    'summary' => 'Node config restored.',
                ],
            ],
        );
        $report['healthy'] = true;
        $report['summary']['fixed'] = 1;

        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->forgetInstance(GatewayApiClient::class);
        app()->forgetInstance(GatewayLogStreamClient::class);
        app()->forgetInstance(GatewayStreamClient::class);
        Http::fake([
            'https://gateway.test/api/doctor/fix' => Http::response(
                doctorRunCompleteStream($report),
                200,
                ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--node' => 'beast',
            '--family' => ['node'],
            '--restore' => true,
        ]);

        $plain = stripAnsi($output);

        expect($exitCode)->toBe(0)
            ->and($plain)->toContain('D O C T O R  R E S T O R E')
            ->and($plain)->toContain('Node config restored.')
            ->and($plain)->toContain('No issues remaining; 1 actions completed');
    });

    it('keeps --json output exactly unchanged', function (): void {
        fakeDoctorRunStream(doctorRunCompleteStream(doctorVerifyReport([])));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--node' => 'beast',
            '--family' => ['node'],
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0);

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['event'])->toBe('complete')
            ->and($payload['data']['data']['doctor']['healthy'])->toBeTrue()
            ->and($payload['data']['data']['doctor']['scope']['node'])->toBe('beast')
            // No framed panel must leak into JSON output.
            ->and($output)->not->toContain('D O C T O R')
            ->and($output)->not->toContain('S U M M A R Y');
    });

    it('keeps --json drift output exactly unchanged', function (): void {
        $report = doctorVerifyReport([
            [
                'family' => 'node',
                'node' => 'beast',
                'key' => 'node.wireguard_peer_missing',
                'code' => 'node.wireguard_peer_missing',
                'kind' => 'missing',
                'summary' => 'WireGuard peer for node beast is missing.',
                'detail' => [],
                'restorable' => true,
                'adoptable' => false,
            ],
        ]);

        fakeDoctorRunStream(doctorRunDriftStream($report));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--node' => 'beast',
            '--family' => ['node'],
            '--json' => true,
        ]);

        expect($exitCode)->toBe(1);

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['event'])->toBe('error')
            ->and($payload['data']['data']['data']['doctor']['issues'])->toHaveCount(1)
            ->and($output)->not->toContain('S U M M A R Y');
    });
});

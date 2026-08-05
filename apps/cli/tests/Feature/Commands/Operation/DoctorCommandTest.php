<?php

declare(strict_types=1);

use App\Services\Doctor\DoctorPanelRenderer;
use App\Services\GatewayStreamClient;
use App\Services\OrbitConfigStore;
use App\Services\StreamJsonIdleStepWriter;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Progress\ProgressEventType;

/**
 * Strip ANSI escape sequences so panel substrings can be matched on the
 * visible text the operator sees.
 */
function stripAnsi(string $value): string
{
    return preg_replace('/\e\[[0-9;?]*[a-zA-Z]/', '', $value) ?? $value;
}

function assertDoctorPanelLinesWithinWidth(string $plain, int $maxWidth = 80): void
{
    foreach (explode("\n", $plain) as $line) {
        expect(mb_strlen($line))->toBeLessThanOrEqual($maxWidth);
    }
}

/**
 * @param  list<array<string, mixed>>  $issues
 * @param  array<string, mixed>  $scopeOverrides
 * @return array<string, mixed>
 */
function doctorVerifyReport(
    array $issues,
    array $scopeOverrides = [],
    string $mode = 'verify',
    array $actions = [],
): array {
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
            'instance' => null,
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
 * @return array<string, mixed>
 */
function doctorFleetReport(): array
{
    return [
        'healthy' => true,
        'mode' => 'verify',
        'scope' => [
            'families' => ['node'],
            'node' => null,
            'role' => 'fleet',
            'self' => false,
            'app' => null,
            'instance' => null,
            'workspace' => null,
            'key' => null,
            'targets' => ['app-1', 'gateway-1'],
        ],
        'summary' => [
            'issues' => 0,
            'fixed' => 0,
            'adopted' => 0,
            'skipped' => 0,
            'conflicts' => 0,
            'failed' => 0,
            'planned' => 0,
        ],
        'issues' => [],
        'actions' => [],
        'nodes' => [
            [
                'node' => 'app-1',
                'role' => 'app-dev',
                'roles' => ['app-dev'],
                'healthy' => true,
                'families' => ['node'],
                'summary' => ['issues' => 0],
            ],
            [
                'node' => 'gateway-1',
                'role' => 'gateway',
                'roles' => ['gateway'],
                'healthy' => true,
                'families' => ['node'],
                'summary' => ['issues' => 0],
            ],
        ],
    ];
}

/**
 * @param  list<string>  $families
 */
function doctorRunCompleteStream(array $doctor, array $families = ['node']): string
{
    return (
        gatewayProgressFrame('tree', [
            'title' => 'Running Doctor',
            'steps' => array_map(fn (string $family): array => [
                'key' => $family,
                'label' => "Check {$family}",
            ], $families),
        ])
        .gatewayProgressFrame('complete', [
            'exit_code' => 0,
            'data' => [
                'footer' => 'Doctor completed.',
                'doctor' => $doctor,
            ],
        ])
    );
}

/**
 * @param  list<string>  $families
 */
function doctorRunDriftStream(array $doctor, array $families = ['node']): string
{
    return (
        gatewayProgressFrame('tree', [
            'title' => 'Running Doctor',
            'steps' => array_map(fn (string $family): array => [
                'key' => $family,
                'label' => "Check {$family}",
            ], $families),
        ])
        .gatewayProgressFrame('error', [
            'exit_code' => 1,
            'message' => 'Doctor detected drift.',
            'data' => [
                'code' => 'drift_detected',
                'message' => 'Doctor detected drift.',
                'meta' => [],
                'data' => ['doctor' => $doctor],
                'footer' => 'Doctor detected drift.',
            ],
        ])
    );
}

/**
 * @param  array<string, mixed>  $doctor
 */
function doctorRunProgressFrame(array $doctor, string $key = '__doctor_panel', string $status = 'running'): string
{
    return gatewayProgressFrame('step', [
        'key' => $key,
        'status' => $status,
        'message' => 'Doctor progress',
        'doctor' => $doctor,
    ]);
}

function fakeDoctorRunStream(string $body, int $status = 200): void
{
    fakeGatewayProgressStream($body, $status);
}

/**
 * @return list<array<string, mixed>>
 */
function decodeDoctorNdjson(string $output): array
{
    $lines = array_values(array_filter(explode("\n", $output)));

    return array_map(fn (string $line): array => json_decode(
        $line,
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    ), $lines);
}

/**
 * @return list<array<string, mixed>>
 */
function doctorIssuesForFamily(string $family, int $count, string $node = 'beast'): array
{
    $issues = [];

    for ($index = 1; $index <= $count; $index++) {
        $issues[] = [
            'family' => $family,
            'node' => $node,
            'key' => "{$family}.issue_{$index}",
            'code' => "{$family}.issue_{$index}",
            'kind' => 'divergent',
            'summary' => "Issue {$index} for truncation coverage.",
            'detail' => [],
            'restorable' => false,
            'adoptable' => false,
        ];
    }

    return $issues;
}

/**
 * @param  list<array<string, mixed>>  $issues
 * @return array<string, mixed>
 */
/**
 * @param  list<array<string, mixed>>  $issues
 * @param  list<array{node: string, status: string}>  $progressNodes
 * @param  list<string>  $targets
 * @return array<string, mixed>
 */
function doctorPartialFleetProgressReport(
    array $issues,
    array $progressNodes,
    array $targets = ['app-dev-1', 'app-prod-1'],
): array {
    $nodes = [];

    foreach ($targets as $target) {
        $nodeIssues = array_values(array_filter(
            $issues,
            static fn (array $issue): bool => ($issue['node'] ?? null) === $target,
        ));
        $nodes[] = [
            'node' => $target,
            'role' => 'app-dev',
            'healthy' => $nodeIssues === [],
            'families' => ['proxy'],
            'summary' => ['issues' => count($nodeIssues)],
        ];
    }

    return [
        'healthy' => $issues === [],
        'mode' => 'verify',
        'scope' => [
            'families' => ['proxy'],
            'node' => null,
            'role' => 'fleet',
            'self' => false,
            'app' => null,
            'instance' => null,
            'workspace' => null,
            'key' => null,
            'targets' => $targets,
        ],
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
        'actions' => [],
        'nodes' => $nodes,
        'progress' => [
            'state' => 'running',
            'nodes' => $progressNodes,
        ],
    ];
}

function doctorFleetDriftReport(array $issues): array
{
    $report = doctorFleetReport();
    $report['healthy'] = false;
    $report['issues'] = $issues;
    $report['summary']['issues'] = count($issues);

    foreach ($report['nodes'] as &$node) {
        $nodeName = is_string($node['node'] ?? null) ? $node['node'] : '';
        $nodeIssueCount = count(array_filter(
            $issues,
            static fn (array $issue): bool => ($issue['node'] ?? null) === $nodeName,
        ));
        $node['healthy'] = $nodeIssueCount === 0;
        $node['summary']['issues'] = $nodeIssueCount;
    }
    unset($node);

    return $report;
}

describe('doctor human panel', function (): void {
    it('keeps non-decorated human output to one final doctor panel instead of full-frame progress spam', function (): void {
        $families = ['node', 'instance'];
        $appIssue = [
            'family' => 'instance',
            'node' => 'beast',
            'key' => 'instance.runtime_container_missing',
            'code' => 'instance.runtime_container_missing',
            'kind' => 'missing',
            'summary' => 'Runtime container for nckrtl is missing.',
            'detail' => ['app' => 'nckrtl'],
            'restorable' => true,
            'adoptable' => false,
        ];
        $initialProgress = doctorVerifyReport([], ['families' => $families]);
        $initialProgress['progress'] = [
            'state' => 'running',
            'families' => [
                ['family' => 'node', 'status' => 'checking'],
                ['family' => 'instance', 'status' => 'queued'],
            ],
        ];
        $partialProgress = doctorVerifyReport([$appIssue], ['families' => $families]);
        $partialProgress['progress'] = [
            'state' => 'running',
            'families' => [
                ['family' => 'node', 'status' => 'ok'],
                ['family' => 'instance', 'status' => 'done'],
            ],
        ];
        $finalReport = doctorVerifyReport([$appIssue], ['families' => $families]);

        fakeDoctorRunStream(
            doctorRunProgressFrame($initialProgress).doctorRunProgressFrame($partialProgress, 'instance', 'done')
                .doctorRunDriftStream($finalReport, $families),
        );

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--node' => 'beast',
            '--family' => $families,
        ]);

        $plain = stripAnsi($output);

        expect($exitCode)
            ->toBe(1)
            ->and(substr_count($plain, 'D O C T O R I N G'))
            ->toBe(0)
            ->and(substr_count($plain, 'D O C T O R - R E S U L T'))
            ->toBe(1)
            ->and($plain)
            ->toContain('Successfully performed check-up on beast')
            ->and($plain)
            ->toContain('Runtime container for nckrtl is missing.');
    });

    it('repaints the single live doctor panel in decorated human output', function (): void {
        $families = ['node'];
        $initialProgress = doctorVerifyReport([], ['families' => $families]);
        $initialProgress['progress'] = [
            'state' => 'running',
            'families' => [
                ['family' => 'node', 'status' => 'checking'],
            ],
        ];
        $initialPanelLineCount = count(app(DoctorPanelRenderer::class)->lines($initialProgress));
        $finalReport = doctorVerifyReport([], ['families' => $families]);

        fakeDoctorRunStream(gatewayProgressFrame('tree', [
            'title' => 'Running Doctor',
            'steps' => array_map(fn (string $family): array => [
                'key' => $family,
                'label' => "Check {$family}",
            ], $families),
        ]).doctorRunProgressFrame($initialProgress)
            .gatewayProgressFrame('complete', [
                'exit_code' => 0,
                'data' => [
                    'footer' => 'Doctor completed.',
                    'doctor' => $finalReport,
                ],
            ]));

        [$exitCode, $output] = runDecoratedCommand($this, 'doctor', [
            '--node' => 'beast',
            '--family' => $families,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain("\e[2K")
            ->and(substr($output, 0, (int) strrpos($output, 'D O C T O R - R E S U L T')))
            ->toContain("\e[?25h\n\e[".($initialPanelLineCount + 1).'A')
            ->and($output)
            ->toContain('D O C T O R - R E S U L T');
    });

    it('does not render Checking - 100% on active family rows before terminal status', function (): void {
        $families = ['node', 'instance', 'proxy', 'tool', 'database_connection'];
        $progress = doctorVerifyReport([], ['families' => $families]);
        $progress['progress'] = [
            'state' => 'running',
            'families' => [
                ['family' => 'node', 'status' => 'checking', 'completed' => 1, 'total' => 1],
                ['family' => 'instance', 'status' => 'checking', 'completed' => 3, 'total' => 3],
                ['family' => 'proxy', 'status' => 'checking', 'completed' => 2, 'total' => 2],
                ['family' => 'tool', 'status' => 'checking', 'completed' => 4, 'total' => 4],
                ['family' => 'database_connection', 'status' => 'checking', 'completed' => 1, 'total' => 1],
            ],
        ];

        fakeDoctorRunStream(
            doctorRunProgressFrame($progress)
                .doctorRunCompleteStream(doctorVerifyReport([], ['families' => $families]), $families),
        );

        [$exitCode, $output] = runDecoratedCommand($this, 'doctor', [
            '--node' => 'beast',
            '--family' => $families,
        ]);

        $terminalMarker = 'D O C T O R - R E S U L T';
        $inProgressOutput = stripAnsi(substr($output, 0, (int) strrpos($output, $terminalMarker)));

        expect($exitCode)
            ->toBe(0)
            ->and($inProgressOutput)
            ->not->toContain('Checking - 100%')->and($inProgressOutput)->toContain('Checking')->and($inProgressOutput)
            ->not->toContain('S U M M A R Y');
    });

    it('renders count-based Checking - N% when gateway completed and total counts exist', function (): void {
        $families = ['workspace'];
        $progress = doctorVerifyReport([], ['families' => $families]);
        $progress['progress'] = [
            'state' => 'running',
            'families' => [
                [
                    'family' => 'workspace',
                    'status' => 'checking',
                    'completed' => 1,
                    'total' => 2,
                ],
            ],
        ];

        fakeDoctorRunStream(
            doctorRunProgressFrame($progress)
                .doctorRunCompleteStream(doctorVerifyReport([], ['families' => $families]), $families),
        );

        [$exitCode, $output] = runDecoratedCommand($this, 'doctor', [
            '--node' => 'beast',
            '--family' => $families,
        ]);

        $plain = stripAnsi($output);

        expect($exitCode)
            ->toBe(0)
            ->and($plain)
            ->toContain('Checking - 50%')
            ->and($plain)
            ->not->toContain('Checking - 50%%');
    });

    it('keeps plain Checking when completed or total counts are absent', function (): void {
        $families = ['node'];
        $progress = doctorVerifyReport([], ['families' => $families]);
        $progress['progress'] = [
            'state' => 'running',
            'families' => [
                ['family' => 'node', 'status' => 'checking'],
            ],
        ];

        fakeDoctorRunStream(
            doctorRunProgressFrame($progress)
                .doctorRunCompleteStream(doctorVerifyReport([], ['families' => $families]), $families),
        );

        [$exitCode, $output] = runDecoratedCommand($this, 'doctor', [
            '--node' => 'beast',
            '--family' => $families,
        ]);

        $plain = stripAnsi($output);

        expect($exitCode)->toBe(0)->and($plain)->toContain('Checking')->and($plain)->not->toContain('Checking -');
    });

    it('keeps the doctor progress panel spinner blinking during idle waits', function (): void {
        $families = ['node'];
        $initialProgress = doctorVerifyReport([], ['families' => $families]);
        $initialProgress['progress'] = [
            'state' => 'running',
            'families' => [
                ['family' => 'node', 'status' => 'checking'],
            ],
        ];
        $finalReport = doctorVerifyReport([], ['families' => $families]);

        app()->forgetInstance(GatewayStreamClient::class);
        app()->instance(GatewayStreamClient::class, new class($initialProgress, $finalReport) {
            /**
             * @param  array<string, mixed>  $initialProgress
             * @param  array<string, mixed>  $finalReport
             */
            public function __construct(
                private readonly array $initialProgress,
                private readonly array $finalReport,
            ) {}

            /**
             * @param  array<string, mixed>  $payload
             * @param  callable(ProgressEventType, array<string, mixed>): void  $onEvent
             *
             * @mago-ignore lint:excessive-parameter-list
             */
            public function streamEvents(
                string $path,
                array $payload,
                callable $onEvent,
                string $method = 'post',
                ?callable $onIdle = null,
                int $idleIntervalMicroseconds = 300_000,
            ): int {
                $onEvent(ProgressEventType::Step, [
                    'key' => '__doctor_panel',
                    'status' => 'running',
                    'doctor' => $this->initialProgress,
                ]);

                $onIdle?->__invoke();

                $onEvent(ProgressEventType::Complete, [
                    'exit_code' => 0,
                    'data' => ['doctor' => $this->finalReport],
                ]);

                return 0;
            }
        });

        [$exitCode, $output] = runDecoratedCommand($this, 'doctor', [
            '--node' => 'beast',
            '--family' => $families,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain("\e[36m○\e[39m  Node          Checking")
            ->and($output)
            ->toContain("\e[36m◉\e[39m  Node          Checking")
            ->and($output)
            ->toContain('D O C T O R - R E S U L T');
    });

    it('keeps the doctor progress panel spinner blinking for Checking - N% rows', function (): void {
        $families = ['workspace'];
        $progress = doctorVerifyReport([], ['families' => $families]);
        $progress['progress'] = [
            'state' => 'running',
            'families' => [
                [
                    'family' => 'workspace',
                    'status' => 'checking',
                    'completed' => 1,
                    'total' => 2,
                ],
            ],
        ];
        $finalReport = doctorVerifyReport([], ['families' => $families]);

        app()->forgetInstance(GatewayStreamClient::class);
        app()->instance(GatewayStreamClient::class, new class($progress, $finalReport) {
            /**
             * @param  array<string, mixed>  $progress
             * @param  array<string, mixed>  $finalReport
             */
            public function __construct(
                private readonly array $progress,
                private readonly array $finalReport,
            ) {}

            /**
             * @param  array<string, mixed>  $payload
             * @param  callable(ProgressEventType, array<string, mixed>): void  $onEvent
             *
             * @mago-ignore lint:excessive-parameter-list
             */
            public function streamEvents(
                string $path,
                array $payload,
                callable $onEvent,
                string $method = 'post',
                ?callable $onIdle = null,
                int $idleIntervalMicroseconds = 300_000,
            ): int {
                $onEvent(ProgressEventType::Step, [
                    'key' => '__doctor_panel',
                    'status' => 'running',
                    'doctor' => $this->progress,
                ]);

                $onIdle?->__invoke();

                $onEvent(ProgressEventType::Complete, [
                    'exit_code' => 0,
                    'data' => ['doctor' => $this->finalReport],
                ]);

                return 0;
            }
        });

        [$exitCode, $output] = runDecoratedCommand($this, 'doctor', [
            '--node' => 'beast',
            '--family' => $families,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->toContain("\e[36m○\e[39m  Workspaces    Checking - 50%")
            ->and($output)
            ->toContain("\e[36m◉\e[39m  Workspaces    Checking - 50%")
            ->and($output)
            ->toContain('D O C T O R - R E S U L T');
    });

    it('renders a healthy result panel for a single-node verify run', function (): void {
        fakeDoctorRunStream(doctorRunCompleteStream(doctorVerifyReport([])));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--node' => 'beast',
            '--family' => ['node'],
        ]);

        $plain = stripAnsi($output);

        expect($exitCode)
            ->toBe(0)
            ->and($plain)
            ->toContain('D O C T O R - R E S U L T')
            ->and($plain)
            ->toContain('Successfully performed check-up on beast')
            ->and($plain)
            ->toContain('Node')
            ->and($plain)
            ->toContain('OK')
            ->and($plain)
            ->toContain("\n●  Node          OK")
            ->and($plain)
            ->not->toContain("\n│ ●  Node")->and($plain)->toContain('S U M M A R Y')->and($plain)->toContain(
                'No issues detected',
            )->and($plain)
            ->not->toContain('Run doctor --fix');
    });

    it('renders fleet human output for --all without a fake single-node target', function (): void {
        fakeDoctorRunStream(doctorRunCompleteStream(doctorFleetReport()));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--all' => true,
            '--family' => ['node'],
        ]);

        $plain = stripAnsi($output);

        expect($exitCode)
            ->toBe(0)
            ->and($plain)
            ->toContain('D O C T O R - R E S U L T')
            ->and($plain)
            ->not->toContain('F L E E T  D O C T O R  R E S U L T')->and($plain)
            ->not->toContain('Check app-1')->and($plain)->toContain("\n●  app-1")->and($plain)->toContain(
                "\n●  gateway-1",
            )->and($plain)
            ->not->toContain('App-1')->and($plain)
            ->not->toContain('Gateway-1')->and($plain)->toContain('Successfully performed check-up on fleet')->and(
                $plain,
            )->toContain('No issues detected')->and($plain)
            ->not->toContain('among')->and($plain)
            ->not->toContain('this node');
    });

    it('renders single-node in-progress panel with DOCTOR title and no summary', function (): void {
        $progress = doctorVerifyReport([], ['families' => ['node']]);
        $progress['progress'] = [
            'state' => 'running',
            'families' => [
                ['family' => 'node', 'status' => 'checking'],
            ],
        ];

        $plain = implode("\n", app(DoctorPanelRenderer::class)->lines($progress));

        expect($plain)
            ->toContain('D O C T O R')
            ->and($plain)
            ->not->toContain('D O C T O R I N G')->and($plain)
            ->not->toContain('D O C T O R - R E S U L T')->and($plain)
            ->not->toContain('S U M M A R Y');
    });

    it('truncates single-node verify issue bullets to ten per family with overflow line', function (): void {
        $report = doctorVerifyReport(issues: doctorIssuesForFamily('instance', 11), scopeOverrides: ['families' => [
            'instance',
        ]]);

        fakeDoctorRunStream(doctorRunDriftStream($report, ['instance']));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--node' => 'beast',
            '--family' => ['instance'],
        ]);

        $plain = stripAnsi($output);

        expect($exitCode)
            ->toBe(1)
            ->and(substr_count($plain, "\n│  - Issue "))
            ->toBe(10)
            ->and($plain)
            ->toContain('+ 1 more issue')
            ->and($plain)
            ->not->toContain("\n│  - Issue 11");
    });

    it('renders complete active roles on fleet node rows without breaking the bordered panel', function (): void {
        $report = doctorFleetReport();
        $report['nodes'] = [
            [
                'node' => 'multi-1',
                'role' => 'agent',
                'roles' => ['agent', 'app-dev'],
                'healthy' => true,
                'families' => ['node', 'tool'],
                'summary' => ['issues' => 0],
            ],
        ];
        $report['scope']['targets'] = ['multi-1'];
        $report['scope']['role'] = 'fleet';

        $plain = stripAnsi(implode("\n", app(DoctorPanelRenderer::class)->lines($report)));

        expect($plain)
            ->toContain('multi-1')
            ->and($plain)
            ->toContain('OK · agent, app-dev')
            ->and($plain)
            ->toContain('D O C T O R - R E S U L T')
            ->and($report['scope']['role'])
            ->toBe('fleet');
        assertDoctorPanelLinesWithinWidth($plain);
    });

    it('renders fleet in-progress panel with DOCTOR title and no summary', function (): void {
        $progress = doctorFleetReport();
        $progress['progress'] = [
            'state' => 'running',
            'nodes' => [
                ['node' => 'app-1', 'status' => 'checking'],
                ['node' => 'gateway-1', 'status' => 'queued'],
            ],
        ];

        $plain = stripAnsi(implode("\n", app(DoctorPanelRenderer::class)->lines($progress)));

        expect($plain)
            ->toContain('D O C T O R')
            ->and($plain)
            ->toContain('Performing check-up on fleet')
            ->and($plain)
            ->not->toContain('S U M M A R Y')->and($plain)
            ->not->toContain('Check app-1')->and($plain)->toContain('app-1         Checking')->and($plain)->toContain(
                "\n○  gateway-1",
            )->and($plain)
            ->not->toContain('App-1')->and($plain)
            ->not->toContain('Gateway-1');
    });

    it('renders fleet in-progress doctor panel from command stream path instead of step tree', function (): void {
        $finalReport = doctorFleetReport();
        $finalReport['scope']['targets'] = ['app-prod-1', 'gateway-1'];
        $finalReport['nodes'] = [
            [
                'node' => 'app-prod-1',
                'role' => 'app-dev',
                'healthy' => true,
                'families' => ['node'],
                'summary' => ['issues' => 0],
            ],
            [
                'node' => 'gateway-1',
                'role' => 'gateway',
                'healthy' => true,
                'families' => ['node'],
                'summary' => ['issues' => 0],
            ],
        ];

        fakeDoctorRunStream(gatewayProgressFrame('tree', [
            'title' => 'Running Doctor',
            'steps' => [
                ['key' => 'app-prod-1', 'label' => 'Check app-prod-1'],
                ['key' => 'gateway-1', 'label' => 'Check gateway-1'],
            ],
        ])
            .gatewayProgressFrame('step', [
                'key' => 'app-prod-1',
                'status' => 'running',
                'message' => 'Checking app-prod-1',
            ])
            .gatewayProgressFrame('complete', [
                'exit_code' => 0,
                'data' => [
                    'footer' => 'Doctor completed.',
                    'doctor' => $finalReport,
                ],
            ]));

        [$exitCode, $output] = runDecoratedCommand($this, 'doctor', [
            '--all' => true,
            '--family' => ['node'],
        ]);

        $terminalMarker = 'D O C T O R - R E S U L T';
        $terminalOffset = strrpos($output, $terminalMarker);
        $inProgressOutput = stripAnsi(substr(
            $output,
            0,
            $terminalOffset === false ? strlen($output) : $terminalOffset,
        ));

        expect($exitCode)
            ->toBe(0)
            ->and($terminalOffset)
            ->toBeInt()
            ->and($inProgressOutput)
            ->toContain('D O C T O R')
            ->and($inProgressOutput)
            ->toContain('Performing check-up on fleet')
            ->and($inProgressOutput)
            ->toMatch('/app-prod-1\s+Checking/')
            ->and($inProgressOutput)
            ->toMatch('/gateway-1\s+Queued/')
            ->and($inProgressOutput)
            ->not->toContain('Check app-prod-1')->and($inProgressOutput)
            ->not->toContain('Check gateway-1')->and($inProgressOutput)
            ->not->toContain('F L E E T  D O C T O R  R E S U L T')->and($inProgressOutput)
            ->not->toContain('S U M M A R Y')->and($inProgressOutput)
            ->not->toContain('Running Doctor');
    });

    it('renders completed-node issue details in fleet in-progress panel while later nodes remain queued', function (): void {
        $partialReport = doctorPartialFleetProgressReport(issues: [[
            'family' => 'proxy',
            'node' => 'app-dev-1',
            'key' => 'proxy.node_probe_failed',
            'code' => 'proxy.node_probe_failed',
            'kind' => 'unverifiable',
            'summary' => 'Proxy route scan failed on app-dev-1.',
            'detail' => [],
            'restorable' => false,
            'adoptable' => false,
        ]], progressNodes: [
            ['node' => 'app-dev-1', 'status' => 'done'],
            ['node' => 'app-prod-1', 'status' => 'queued'],
        ]);
        $finalReport = doctorFleetDriftReport($partialReport['issues']);
        $finalReport['scope']['targets'] = ['app-dev-1', 'app-prod-1'];
        $finalReport['nodes'] = $partialReport['nodes'];

        fakeDoctorRunStream(gatewayProgressFrame('tree', [
            'title' => 'Running Doctor',
            'steps' => [
                ['key' => 'app-dev-1', 'label' => 'Check app-dev-1'],
                ['key' => 'app-prod-1', 'label' => 'Check app-prod-1'],
            ],
        ])
            .gatewayProgressFrame('step', [
                'key' => 'app-dev-1',
                'status' => 'running',
                'message' => 'Checking app-dev-1',
            ])
            .gatewayProgressFrame('step', [
                'key' => 'app-dev-1',
                'status' => 'done',
                'message' => 'app-dev-1 checked',
                'doctor' => $partialReport,
            ])
            .gatewayProgressFrame('step', [
                'key' => 'app-prod-1',
                'status' => 'running',
                'message' => 'Checking app-prod-1',
            ])
            .gatewayProgressFrame('error', [
                'exit_code' => 1,
                'message' => 'Doctor detected drift.',
                'data' => [
                    'code' => 'drift_detected',
                    'message' => 'Doctor detected drift.',
                    'meta' => [],
                    'data' => ['doctor' => $finalReport],
                    'footer' => 'Doctor detected drift.',
                ],
            ]));

        [$exitCode, $output] = runDecoratedCommand($this, 'doctor', [
            '--all' => true,
            '--family' => ['proxy'],
        ]);

        $terminalMarker = 'D O C T O R - R E S U L T';
        $terminalOffset = strrpos($output, $terminalMarker);
        $inProgressOutput = stripAnsi(substr(
            $output,
            0,
            $terminalOffset === false ? strlen($output) : $terminalOffset,
        ));

        expect($exitCode)
            ->toBe(1)
            ->and($terminalOffset)
            ->toBeInt()
            ->and($inProgressOutput)
            ->toContain('Proxy route scan failed on app-dev-1.')
            ->and($inProgressOutput)
            ->toMatch('/app-prod-1\s+Checking/')
            ->and($inProgressOutput)
            ->not->toContain('S U M M A R Y')->and($inProgressOutput)
            ->not->toContain('Check app-dev-1');
    });

    it('preserves completed-node issue rows when a later fleet running step has no doctor payload', function (): void {
        $agentIssues = doctorIssuesForFamily('node', 8, 'agent-1');
        $completedReport = doctorPartialFleetProgressReport(
            issues: $agentIssues,
            progressNodes: [
                ['node' => 'agent-1', 'status' => 'done'],
                ['node' => 'app-dev-1', 'status' => 'queued'],
                ['node' => 'app-prod-1', 'status' => 'queued'],
                ['node' => 'gateway', 'status' => 'queued'],
            ],
            targets: ['agent-1', 'app-dev-1', 'app-prod-1', 'gateway'],
        );
        $finalReport = doctorFleetDriftReport($agentIssues);
        $finalReport['scope']['targets'] = ['agent-1', 'app-dev-1', 'app-prod-1', 'gateway'];
        $finalReport['nodes'] = $completedReport['nodes'];

        fakeDoctorRunStream(gatewayProgressFrame('tree', [
            'title' => 'Running Doctor',
            'steps' => [
                ['key' => 'agent-1', 'label' => 'Check agent-1'],
                ['key' => 'app-dev-1', 'label' => 'Check app-dev-1'],
                ['key' => 'app-prod-1', 'label' => 'Check app-prod-1'],
                ['key' => 'gateway', 'label' => 'Check gateway'],
            ],
        ])
            .gatewayProgressFrame('step', [
                'key' => 'agent-1',
                'status' => 'done',
                'message' => 'agent-1 checked',
                'doctor' => $completedReport,
            ])
            .gatewayProgressFrame('step', [
                'key' => 'app-dev-1',
                'status' => 'running',
                'message' => 'Checking app-dev-1',
            ])
            .gatewayProgressFrame('error', [
                'exit_code' => 1,
                'message' => 'Doctor detected drift.',
                'data' => [
                    'code' => 'drift_detected',
                    'message' => 'Doctor detected drift.',
                    'meta' => [],
                    'data' => ['doctor' => $finalReport],
                    'footer' => 'Doctor detected drift.',
                ],
            ]));

        [$exitCode, $output] = runDecoratedCommand($this, 'doctor', [
            '--all' => true,
            '--family' => ['node'],
        ]);

        $terminalMarker = 'D O C T O R - R E S U L T';
        $inProgressOutput = stripAnsi(substr($output, 0, (int) strrpos($output, $terminalMarker)));

        expect($exitCode)
            ->toBe(1)
            ->and($inProgressOutput)
            ->toContain('8 issues found')
            ->and($inProgressOutput)
            ->not->toMatch('/agent-1\s+OK/')->and($inProgressOutput)->toMatch('/app-dev-1\s+Checking/')->and(
                $inProgressOutput,
            )
            ->not->toContain('S U M M A R Y');
    });

    it('renders plain Checking on active fleet node rows when per-node progress totals are absent', function (): void {
        fakeDoctorRunStream(gatewayProgressFrame('tree', [
            'title' => 'Running Doctor',
            'steps' => [
                ['key' => 'agent-1', 'label' => 'Check agent-1'],
                ['key' => 'app-dev-1', 'label' => 'Check app-dev-1'],
                ['key' => 'app-prod-1', 'label' => 'Check app-prod-1'],
            ],
        ])
            .gatewayProgressFrame('step', [
                'key' => 'app-dev-1',
                'status' => 'running',
                'message' => 'Checking app-dev-1',
            ])
            .gatewayProgressFrame('complete', [
                'exit_code' => 0,
                'data' => [
                    'footer' => 'Doctor completed.',
                    'doctor' => doctorFleetReport(),
                ],
            ]));

        [$exitCode, $output] = runDecoratedCommand($this, 'doctor', [
            '--all' => true,
            '--family' => ['node'],
        ]);

        $terminalMarker = 'D O C T O R - R E S U L T';
        $inProgressOutput = stripAnsi(substr($output, 0, (int) strrpos($output, $terminalMarker)));

        expect($exitCode)
            ->toBe(0)
            ->and($inProgressOutput)
            ->toMatch('/app-dev-1\s+Checking/')
            ->and($inProgressOutput)
            ->not->toMatch('/app-dev-1\s+Checking -/');
    });

    it('renders count-based Checking - N% on active fleet node rows when progress totals exist', function (): void {
        $partialReport = doctorPartialFleetProgressReport(
            issues: [],
            progressNodes: [
                ['node' => 'agent-1', 'status' => 'done'],
                ['node' => 'app-dev-1', 'status' => 'running', 'completed' => 1, 'total' => 4],
                ['node' => 'app-prod-1', 'status' => 'queued'],
                ['node' => 'gateway', 'status' => 'queued'],
            ],
            targets: ['agent-1', 'app-dev-1', 'app-prod-1', 'gateway'],
        );

        fakeDoctorRunStream(gatewayProgressFrame('tree', [
            'title' => 'Running Doctor',
            'steps' => [
                ['key' => 'agent-1', 'label' => 'Check agent-1'],
                ['key' => 'app-dev-1', 'label' => 'Check app-dev-1'],
                ['key' => 'app-prod-1', 'label' => 'Check app-prod-1'],
                ['key' => 'gateway', 'label' => 'Check gateway'],
            ],
        ])
            .gatewayProgressFrame('step', [
                'key' => 'app-dev-1',
                'status' => 'running',
                'message' => 'Checking app-dev-1',
                'doctor' => $partialReport,
            ])
            .gatewayProgressFrame('complete', [
                'exit_code' => 0,
                'data' => [
                    'footer' => 'Doctor completed.',
                    'doctor' => doctorFleetReport(),
                ],
            ]));

        [$exitCode, $output] = runDecoratedCommand($this, 'doctor', [
            '--all' => true,
            '--family' => ['node'],
        ]);

        $terminalMarker = 'D O C T O R - R E S U L T';
        $inProgressOutput = stripAnsi(substr($output, 0, (int) strrpos($output, $terminalMarker)));

        expect($exitCode)
            ->toBe(0)
            ->and($inProgressOutput)
            ->toContain('Checking - 25%')
            ->and($inProgressOutput)
            ->not->toContain('Checking - 100%')->and($inProgressOutput)
            ->not->toContain('S U M M A R Y');

        assertDoctorPanelLinesWithinWidth($inProgressOutput);
    });

    it('caps completed-node fleet in-progress issue bullets at ten with overflow line', function (): void {
        $issues = doctorIssuesForFamily('proxy', 11, 'app-dev-1');
        $partialReport = doctorPartialFleetProgressReport(issues: $issues, progressNodes: [
            ['node' => 'app-dev-1', 'status' => 'done'],
            ['node' => 'app-prod-1', 'status' => 'running'],
        ]);
        $finalReport = doctorFleetDriftReport($issues);
        $finalReport['scope']['targets'] = ['app-dev-1', 'app-prod-1'];
        $finalReport['nodes'] = $partialReport['nodes'];

        fakeDoctorRunStream(gatewayProgressFrame('tree', [
            'title' => 'Running Doctor',
            'steps' => [
                ['key' => 'app-dev-1', 'label' => 'Check app-dev-1'],
                ['key' => 'app-prod-1', 'label' => 'Check app-prod-1'],
            ],
        ])
            .gatewayProgressFrame('step', [
                'key' => 'app-dev-1',
                'status' => 'done',
                'message' => 'app-dev-1 checked',
                'doctor' => $partialReport,
            ])
            .gatewayProgressFrame('step', [
                'key' => 'app-prod-1',
                'status' => 'running',
                'message' => 'Checking app-prod-1',
            ])
            .gatewayProgressFrame('error', [
                'exit_code' => 1,
                'message' => 'Doctor detected drift.',
                'data' => [
                    'code' => 'drift_detected',
                    'message' => 'Doctor detected drift.',
                    'meta' => [],
                    'data' => ['doctor' => $finalReport],
                    'footer' => 'Doctor detected drift.',
                ],
            ]));

        [$exitCode, $output] = runDecoratedCommand($this, 'doctor', [
            '--all' => true,
            '--family' => ['proxy'],
        ]);

        $terminalMarker = 'D O C T O R - R E S U L T';
        $inProgressOutput = stripAnsi(substr($output, 0, (int) strrpos($output, $terminalMarker)));

        expect($exitCode)
            ->toBe(1)
            ->and(substr_count($inProgressOutput, "\n│  - Issue "))
            ->toBe(10)
            ->and($inProgressOutput)
            ->toContain('+ 1 more issue')
            ->and($inProgressOutput)
            ->not->toContain("\n│  - Issue 11");
    });

    it('renders fleet terminal panel with summary and flat per-node issue bullets', function (): void {
        $report = doctorFleetDriftReport([
            [
                'family' => 'proxy',
                'node' => 'app-1',
                'key' => 'proxy.node_probe_failed',
                'code' => 'proxy.node_probe_failed',
                'kind' => 'unverifiable',
                'summary' => 'Proxy route scan failed on app-1.',
                'detail' => [],
                'restorable' => false,
                'adoptable' => false,
            ],
        ]);

        fakeDoctorRunStream(doctorRunDriftStream($report));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--all' => true,
            '--family' => ['proxy'],
        ]);

        $plain = stripAnsi($output);

        expect($exitCode)
            ->toBe(1)
            ->and($plain)
            ->toContain('D O C T O R - R E S U L T')
            ->and($plain)
            ->toContain('S U M M A R Y')
            ->and($plain)
            ->toContain("\n●  app-1")
            ->and($plain)
            ->toContain("\n●  gateway-1")
            ->and($plain)
            ->not->toContain('App-1')->and($plain)
            ->not->toContain('Gateway-1')->and($plain)->toContain('1 issue detected among 1 node')->and(
                $plain,
            )->toContain('Proxy route scan failed on app-1.')->and($plain)
            ->not->toContain('Check app-1');
    });

    it('renders fleet terminal summary with affected node count across multiple nodes', function (): void {
        $report = doctorFleetDriftReport([
            [
                'family' => 'proxy',
                'node' => 'app-1',
                'key' => 'proxy.node_probe_failed',
                'code' => 'proxy.node_probe_failed',
                'kind' => 'unverifiable',
                'summary' => 'Proxy route scan failed on app-1.',
                'detail' => [],
                'restorable' => false,
                'adoptable' => false,
            ],
            [
                'family' => 'node',
                'node' => 'gateway-1',
                'key' => 'node.wireguard_peer_missing',
                'code' => 'node.wireguard_peer_missing',
                'kind' => 'missing',
                'summary' => 'WireGuard peer for node gateway-1 is missing.',
                'detail' => [],
                'restorable' => true,
                'adoptable' => false,
            ],
        ]);

        fakeDoctorRunStream(doctorRunDriftStream($report));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--all' => true,
            '--family' => ['proxy', 'node'],
        ]);

        $plain = stripAnsi($output);

        expect($exitCode)->toBe(1)->and($plain)->toContain('2 issues detected among 2 nodes');
    });

    it('truncates fleet verify issue bullets to ten per node with overflow line', function (): void {
        $issues = doctorIssuesForFamily('instance', 11, 'app-1');

        fakeDoctorRunStream(doctorRunDriftStream(doctorFleetDriftReport($issues)));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--all' => true,
            '--family' => ['instance'],
        ]);

        $plain = stripAnsi($output);

        expect($exitCode)
            ->toBe(1)
            ->and(substr_count($plain, "\n│  - Issue "))
            ->toBe(10)
            ->and($plain)
            ->toContain('+ 1 more issue')
            ->and($plain)
            ->toContain('11 issues detected among 1 node');
    });

    it('keeps complete issue payloads in --json when human bullets are truncated', function (): void {
        $report = doctorVerifyReport(issues: doctorIssuesForFamily('instance', 11), scopeOverrides: ['families' => [
            'instance',
        ]]);

        fakeDoctorRunStream(doctorRunDriftStream($report, ['instance']));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--node' => 'beast',
            '--family' => ['instance'],
            '--json' => true,
        ]);

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
        $issues = $payload['data']['data']['data']['doctor']['issues'] ?? [];

        expect($exitCode)->toBe(1)->and($issues)->toHaveCount(11)->and($output)->not->toContain('+ 1 more issue');
    });

    it('keeps complete terminal fleet issues in --stream-json when human bullets are truncated', function (): void {
        $issues = doctorIssuesForFamily('proxy', 11, 'app-dev-1');
        $partialReport = doctorPartialFleetProgressReport(issues: $issues, progressNodes: [
            ['node' => 'app-dev-1', 'status' => 'done'],
            ['node' => 'app-prod-1', 'status' => 'running'],
        ]);
        $finalReport = doctorFleetDriftReport($issues);
        $finalReport['scope']['targets'] = ['app-dev-1', 'app-prod-1'];
        $finalReport['nodes'] = $partialReport['nodes'];

        fakeDoctorRunStream(gatewayProgressFrame('tree', [
            'title' => 'Running Doctor',
            'steps' => [
                ['key' => 'app-dev-1', 'label' => 'Check app-dev-1'],
                ['key' => 'app-prod-1', 'label' => 'Check app-prod-1'],
            ],
        ])
            .gatewayProgressFrame('step', [
                'key' => 'app-dev-1',
                'status' => 'done',
                'message' => 'app-dev-1 checked',
                'doctor' => $partialReport,
            ])
            .gatewayProgressFrame('error', [
                'exit_code' => 1,
                'message' => 'Doctor detected drift.',
                'data' => [
                    'code' => 'drift_detected',
                    'message' => 'Doctor detected drift.',
                    'meta' => [],
                    'data' => ['doctor' => $finalReport],
                    'footer' => 'Doctor detected drift.',
                ],
            ]));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--all' => true,
            '--family' => ['proxy'],
            '--stream-json' => true,
        ]);

        $frames = decodeDoctorNdjson($output);
        $partialFrame = $frames[1] ?? null;
        $terminal = end($frames);
        $terminalIssues = $terminal['error']['data']['doctor']['issues'] ?? [];

        expect($exitCode)
            ->toBe(1)
            ->and($partialFrame['event'] ?? null)
            ->toBe('step')
            // Intermediate machine frames may be compact; terminal stays full.
            ->and($terminalIssues)
            ->toHaveCount(11)
            ->and($output)
            ->not->toContain('+ 1 more issue')->and($output)
            ->not->toMatch('/\+ \d+ more issues/');
    });

    it('keeps complete issue payloads in --stream-json when human bullets are truncated', function (): void {
        $report = doctorVerifyReport(issues: doctorIssuesForFamily('instance', 11), scopeOverrides: ['families' => [
            'instance',
        ]]);

        fakeDoctorRunStream(doctorRunDriftStream($report, ['instance']));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--node' => 'beast',
            '--family' => ['instance'],
            '--stream-json' => true,
        ]);

        $frames = decodeDoctorNdjson($output);
        $terminal = end($frames);
        $issues = $terminal['error']['data']['doctor']['issues'] ?? [];

        expect($exitCode)
            ->toBe(1)
            ->and($terminal['event'])
            ->toBe('error')
            ->and($issues)
            ->toHaveCount(11)
            ->and($output)
            ->not->toContain('+ 1 more issue')->and($output)
            ->not->toMatch('/\+ \d+ more issues/');
    });

    it('renders verify issues as readable bullet details and summary next-action line', function (): void {
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

        expect($exitCode)
            ->toBe(1)
            ->and($plain)
            ->toContain('D O C T O R - R E S U L T')
            ->and($plain)
            ->toContain("\n●  Node          1 issue detected:")
            ->and($plain)
            ->toContain("\n│  ".str_repeat('-', 74).'  │')
            ->and($plain)
            ->toContain("\n│  - WireGuard peer for node beast is missing.")
            ->and($plain)
            ->not->toContain("\n│  ".str_repeat('-', 75).' │')->and($plain)
            ->not->toContain("\n│   ".str_repeat('-', 74).'  │')->and($plain)
            ->not->toContain("\n│   - WireGuard peer for node beast is missing.")->and($plain)->toContain(
                'S U M M A R Y',
            )->and($plain)->toContain('Run doctor --fix manually or through an LLM to resolve issues')
            // node family table must not carry a NODE column.
            ->and($plain)
            ->not->toContain('NODE')->and($plain)
            ->not->toContain('ISSUE')->and($plain)
            ->not->toContain('node.wireguard_peer_missing');
    });

    it('renders verify-mode family issues as readable bullet details in active-role order', function (): void {
        $report = doctorVerifyReport(issues: [
            [
                'family' => 'instance',
                'node' => 'beast',
                'key' => 'instance.http_error',
                'code' => 'instance.http_error',
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
            [
                'family' => 'process',
                'node' => 'beast',
                'key' => 'process.runtime_unit_missing',
                'code' => 'process.runtime_unit_missing',
                'kind' => 'missing',
                'summary' => 'process.runtime_unit_missing',
                'detail' => ['app' => 'nckrtl', 'process' => 'queue-worker'],
                'restorable' => true,
                'adoptable' => false,
            ],
            [
                'family' => 'database_connection',
                'node' => 'beast',
                'key' => 'database_connection.env_mismatch',
                'code' => 'database_connection.env_mismatch',
                'kind' => 'divergent',
                'summary' => 'database_connection.env_mismatch',
                'detail' => ['connection' => 'ditis_hr'],
                'restorable' => true,
                'adoptable' => true,
            ],
            [
                'family' => 'database_connection',
                'node' => 'beast',
                'key' => 'database_connection.env_mismatch',
                'code' => 'database_connection.env_mismatch',
                'kind' => 'divergent',
                'summary' => 'database_connection.env_mismatch',
                'detail' => ['target_type' => 'project', 'app' => 'nckrtl', 'env_prefix' => 'REPORTING'],
                'restorable' => true,
                'adoptable' => true,
            ],
            [
                'family' => 'schedule',
                'node' => 'beast',
                'key' => 'schedule.lock_stuck',
                'code' => 'schedule.lock_stuck',
                'kind' => 'divergent',
                'summary' => 'schedule.lock_stuck',
                'detail' => ['schedule_key' => 'instance:docs.production:laravel-scheduler'],
                'restorable' => true,
                'adoptable' => false,
            ],
        ], scopeOverrides: ['families' => [
            'node',
            'instance',
            'workspace',
            'process',
            'proxy',
            'firewall_rule',
            'tool',
            'schedule',
            'database_connection',
        ]]);

        fakeDoctorRunStream(doctorRunDriftStream($report, [
            'node',
            'instance',
            'workspace',
            'process',
            'proxy',
            'firewall_rule',
            'tool',
            'schedule',
            'database_connection',
        ]));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--node' => 'beast',
        ]);

        $plain = stripAnsi($output);

        expect($exitCode)
            ->toBe(1)
            // Category labels from the catalog, role-derived order.
            ->and($plain)
            ->toContain('Instances')
            ->and($plain)
            ->toContain('Workspaces')
            ->and($plain)
            ->toContain('Proxy routes')
            ->and($plain)
            ->toContain('Firewall')
            // Verify mode renders issue details as readable bullets, not nested tables.
            ->and($plain)
            ->toContain("\n●  Instances     1 issue detected:")
            ->and($plain)
            ->toContain('- Instance nckrtl: https://nckrtl.test returned a 500 error')
            ->and($plain)
            ->toContain("\n●  Workspaces    2 issues found:")
            ->and($plain)
            ->toContain('- Workspace abc123.nckrtl.test: Workspace should exist')
            ->and($plain)
            ->toContain('- Workspace ui-redesign.hauser.test: Workspace exists')
            ->and($plain)
            ->toContain("\n●  Processes     1 issue detected:")
            ->and($plain)
            ->toContain('- Process queue-worker for app nckrtl: Runtime unit missing.')
            ->and($plain)
            ->toContain("\n●  Scheduling    1 issue detected:")
            ->and($plain)
            ->toContain('- Schedule instance:docs.production:laravel-scheduler: Lock stuck.')
            ->and($plain)
            ->toContain("\n●  Databases     2 issues found:")
            ->and($plain)
            ->toContain('- Database connection ditis_hr: Environment mismatch.')
            ->and($plain)
            ->toContain('- Database connection REPORTING for app nckrtl: Environment')
            // Categories with no issues render OK.
            ->and($plain)
            ->toContain('OK')
            // Total count summary, never "across N categories".
            ->and($plain)
            ->toContain('7 issues detected')
            ->and($plain)
            ->not->toContain('APP')->and($plain)
            ->not->toContain('WORKSPACE')->and($plain)
            ->not->toContain('ISSUE')->and($plain)
            ->not->toContain('database_connection.env_mismatch')->and($plain)
            ->not->toContain('schedule.lock_stuck')->and($plain)
            ->not->toContain('across');
    });

    it('dims the outer border and dashed issue separator when a category has issues', function (): void {
        $report = doctorVerifyReport(issues: [
            [
                'family' => 'database_connection',
                'node' => 'beast',
                'key' => 'database_connection.missing',
                'code' => 'database_connection.missing',
                'kind' => 'missing',
                'summary' => 'Database connection ditis_hr is missing from the node.',
                'detail' => ['connection' => 'ditis_hr'],
                'restorable' => true,
                'adoptable' => false,
            ],
        ], scopeOverrides: ['families' => ['node', 'database_connection']]);

        fakeDoctorRunStream(doctorRunDriftStream($report, ['node', 'database_connection']));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--node' => 'beast',
            '--family' => ['node', 'database_connection'],
        ]);

        $plain = stripAnsi($output);

        expect($exitCode)
            ->toBe(1)
            ->and($plain)
            ->toContain("\n●  Node          OK")
            ->and($plain)
            ->toContain("\n●  Databases     1 issue detected:")
            ->and($plain)
            ->toContain("\n│  ".str_repeat('-', 74).'  │')
            ->and($plain)
            ->toContain("\n│  - Database connection ditis_hr is missing from the node.")
            ->and($plain)
            ->not->toContain("\n│   - Database connection ditis_hr is missing from the node.")->and($plain)
            ->not->toContain("\n│ ●  Databases")->and($plain)
            ->not->toContain('ISSUE')->and($plain)
            ->not->toContain('database_connection.missing')->and($output)->toMatch(
                '/\e\[31m●\e\[39m  Databases\s+1 issue detected:\s+\e\[38;5;242m│\e\[39m/',
            )->and($output)->toMatch(
                '/\e\[38;5;242m│\e\[39m\s+\e\[38;5;242m-{20,}\e\[39m\s+\e\[38;5;242m│\e\[39m/',
            )->and($output)
            ->not->toContain("\e[38;5;242m- Database connection ditis_hr is missing from the node.");
    });

    it('summarizes unverifiable tool probe failures as issue count with details below the separator', function (): void {
        $summary = 'WebSocket Valkey is unavailable to the Reverb runtime on node app-dev-1.';

        $report = doctorVerifyReport(issues: [
            [
                'family' => 'tool',
                'node' => 'app-dev-1',
                'key' => 'tool.websocket_valkey_unavailable',
                'code' => 'tool.websocket_valkey_unavailable',
                'kind' => 'unverifiable',
                'summary' => $summary,
                'detail' => [],
                'restorable' => false,
                'adoptable' => false,
            ],
        ], scopeOverrides: ['node' => 'app-dev-1', 'families' => ['node', 'tool']]);

        fakeDoctorRunStream(doctorRunDriftStream($report, ['node', 'tool']));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--node' => 'app-dev-1',
            '--family' => ['node', 'tool'],
        ]);

        $plain = stripAnsi($output);

        expect($exitCode)
            ->toBe(1)
            ->and($plain)
            ->toContain("\n●  Tools         1 issue detected:")
            ->and($plain)
            ->not->toContain('Unavailable, WebSocket Valkey')->and($plain)->toContain(
                "\n│  ".str_repeat('-', 74).'  │',
            )->and($plain)->toContain("\n│  - {$summary}")->and($plain)
            ->not->toContain("\n│   - {$summary}");

        assertDoctorPanelLinesWithinWidth($plain);
    });

    it('keeps every stripped human doctor panel line within the panel width', function (): void {
        $report = doctorVerifyReport(issues: [
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
            [
                'family' => 'tool',
                'node' => 'beast',
                'key' => 'tool.websocket_valkey_unavailable',
                'code' => 'tool.websocket_valkey_unavailable',
                'kind' => 'unverifiable',
                'summary' => 'WebSocket Valkey is unavailable to the Reverb runtime on node beast.',
                'detail' => [],
                'restorable' => false,
                'adoptable' => false,
            ],
        ], scopeOverrides: ['families' => ['node', 'tool']]);

        fakeDoctorRunStream(doctorRunDriftStream($report, ['node', 'tool']));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--node' => 'beast',
            '--family' => ['node', 'tool'],
        ]);

        expect($exitCode)->toBe(1);

        assertDoctorPanelLinesWithinWidth(stripAnsi($output));
    });

    it('wraps long unavailable category status text inside the panel', function (): void {
        $longReason = 'WebSocket Valkey is unavailable to the Reverb runtime on node app-dev-1 because the connection pool is exhausted and retries failed.';

        $report = doctorVerifyReport(
            issues: [
                [
                    'family' => 'tool',
                    'node' => 'app-dev-1',
                    'key' => 'tool.websocket_valkey_unavailable',
                    'code' => 'tool.websocket_valkey_unavailable',
                    'kind' => 'unverifiable',
                    'summary' => $longReason,
                    'detail' => [],
                    'restorable' => false,
                    'adoptable' => false,
                ],
            ],
            mode: 'restore',
            scopeOverrides: ['node' => 'app-dev-1', 'families' => ['node', 'tool']],
        );
        $report['healthy'] = false;

        fakeDoctorRunStream(doctorRunDriftStream($report, ['node', 'tool']));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--node' => 'app-dev-1',
            '--family' => ['node', 'tool'],
            '--restore' => true,
        ]);

        $plain = stripAnsi($output);

        expect($exitCode)
            ->toBe(1)
            ->and($plain)
            ->toContain('Unavailable, WebSocket Valkey is unavailable')
            ->and($plain)
            ->toContain('because the connection pool is')
            ->and($plain)
            ->toContain('exhausted and retries failed.')
            ->and($plain)
            ->not->toContain('retries failed.│');

        assertDoctorPanelLinesWithinWidth($plain);
    });

    it('wraps the node reboot-required guidance instead of truncating it', function (): void {
        $guidance =
            'This node requires an explicit reboot to finish installed updates. '
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

        expect($exitCode)
            ->toBe(1)
            ->and($plain)
            ->toContain('- This node requires an explicit reboot to finish installed updates.')
            ->and($plain)
            ->toContain('Orbit will not reboot it automatically.')
            ->and($plain)
            ->toContain('Reboot this server as soon as possible.')
            // Long node summaries wrap rather than truncate with an ellipsis.
            ->and($plain)
            ->not->toContain('…');
    });

    it('renders restore-mode action results and title', function (): void {
        $report = doctorVerifyReport(issues: [], mode: 'restore', actions: [
            [
                'family' => 'node',
                'node' => 'beast',
                'key' => 'node.config',
                'mode' => 'restore',
                'status' => 'completed',
                'summary' => 'Node config restored.',
            ],
        ]);
        $report['healthy'] = true;
        $report['summary']['fixed'] = 1;

        fakeGatewayProgressStreamClient(doctorRunCompleteStream($report));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--node' => 'beast',
            '--family' => ['node'],
            '--restore' => true,
        ]);

        $plain = stripAnsi($output);

        expect($exitCode)
            ->toBe(0)
            ->and($plain)
            ->toContain('D O C T O R  R E S T O R E')
            ->and($plain)
            ->toContain('Node config restored.')
            ->and($plain)
            ->toContain('No issues remaining; 1 actions completed');
    });

    it('does not leak human percentage strings into --json output', function (): void {
        $progress = doctorVerifyReport([], ['families' => ['workspace']]);
        $progress['progress'] = [
            'state' => 'running',
            'families' => [
                [
                    'family' => 'workspace',
                    'status' => 'checking',
                    'completed' => 1,
                    'total' => 2,
                ],
            ],
        ];

        fakeDoctorRunStream(gatewayProgressFrame('tree', [
            'title' => 'Running Doctor',
            'steps' => [['key' => 'workspace', 'label' => 'Check workspace']],
        ]).doctorRunProgressFrame($progress)
            .gatewayProgressFrame('complete', [
                'exit_code' => 0,
                'data' => [
                    'footer' => 'Doctor completed.',
                    'doctor' => doctorVerifyReport([], ['families' => ['workspace']]),
                ],
            ]));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--node' => 'beast',
            '--family' => ['workspace'],
            '--json' => true,
        ]);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->not->toContain('Checking -')->and($output)
            ->not->toContain('D O C T O R');
    });

    it('does not leak human percentage strings into --stream-json output', function (): void {
        $progress = doctorVerifyReport([], ['families' => ['workspace']]);
        $progress['progress'] = [
            'state' => 'running',
            'families' => [
                [
                    'family' => 'workspace',
                    'status' => 'checking',
                    'completed' => 1,
                    'total' => 2,
                ],
            ],
        ];
        $report = doctorVerifyReport([], ['families' => ['workspace']]);

        fakeDoctorRunStream(gatewayProgressFrame('tree', [
            'title' => 'Running Doctor',
            'steps' => [['key' => 'workspace', 'label' => 'Check workspace']],
        ]).doctorRunProgressFrame($progress)
            .gatewayProgressFrame('complete', [
                'exit_code' => 0,
                'data' => [
                    'footer' => 'Doctor completed.',
                    'doctor' => $report,
                ],
            ]));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--node' => 'beast',
            '--family' => ['workspace'],
            '--stream-json' => true,
        ]);

        $frames = decodeDoctorNdjson($output);

        expect($exitCode)
            ->toBe(0)
            ->and($output)
            ->not->toContain('Checking -')->and(collect($frames)->pluck('data')->filter()->values()->all())
            ->not->toBeEmpty();
    });

    it('keeps --json output exactly unchanged', function (): void {
        fakeDoctorRunStream(gatewayProgressFrame('tree', [
            'title' => 'Running Doctor',
            'steps' => [['key' => 'node', 'label' => 'Check node']],
        ]).gatewayProgressFrame('step', ['key' => 'node', 'status' => 'running', 'message' => 'Checking node'])
            .gatewayProgressFrame('complete', [
                'exit_code' => 0,
                'data' => [
                    'footer' => 'Doctor completed.',
                    'doctor' => doctorVerifyReport([]),
                ],
            ]));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--node' => 'beast',
            '--family' => ['node'],
            '--json' => true,
        ]);

        expect($exitCode)->toBe(0);

        $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['event'])
            ->toBe('complete')
            ->and($payload['data']['data']['doctor']['healthy'])
            ->toBeTrue()
            ->and($payload['data']['data']['doctor']['scope']['node'])
            ->toBe('beast')
            ->and(count(array_filter(explode("\n", $output))))
            ->toBe(1)
            // No framed panel must leak into JSON output.
            ->and($output)
            ->not->toContain('D O C T O R')->and($output)
            ->not->toContain('Checking node')->and($output)
            ->not->toContain('S U M M A R Y');
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

        expect($payload['event'])
            ->toBe('error')
            ->and($payload['data']['data']['data']['doctor']['issues'])
            ->toHaveCount(1)
            ->and($output)
            ->not->toContain('S U M M A R Y');
    });

    it('streams doctor progress frames as newline-delimited JSON', function (): void {
        $report = doctorVerifyReport([]);

        fakeDoctorRunStream(gatewayProgressFrame('tree', [
            'title' => 'Running Doctor',
            'steps' => [['key' => 'node', 'label' => 'Check node']],
        ]).gatewayProgressFrame('step', ['key' => 'node', 'status' => 'running', 'message' => 'Checking node'])
            .gatewayProgressFrame('complete', [
                'exit_code' => 0,
                'data' => [
                    'footer' => 'Doctor completed.',
                    'doctor' => $report,
                ],
            ]));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--node' => 'beast',
            '--family' => ['node'],
            '--stream-json' => true,
        ]);

        $frames = decodeDoctorNdjson($output);

        expect($exitCode)
            ->toBe(0)
            ->and($frames)
            ->toBe([
                [
                    'event' => 'tree',
                    'data' => [
                        'title' => 'Running Doctor',
                        'steps' => [['key' => 'node', 'label' => 'Check node']],
                    ],
                ],
                [
                    'event' => 'step',
                    'data' => ['key' => 'node', 'status' => 'running', 'message' => 'Checking node'],
                ],
                [
                    'event' => 'complete',
                    'success' => [
                        'data' => ['doctor' => $report],
                        'meta' => ['exit_code' => 0],
                    ],
                ],
            ])
            ->and($output)
            ->not->toContain("\e[")->and($output)
            ->not->toContain('D O C T O R');
    });

    it('streams doctor bulk resolution modes through the fix endpoint', function (string $mode): void {
        $report = doctorVerifyReport([], mode: $mode);

        fakeGatewayProgressStreamClient(doctorRunCompleteStream($report));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--node' => 'beast',
            '--family' => ['node'],
            "--{$mode}" => true,
            '--stream-json' => true,
        ]);

        $frames = decodeDoctorNdjson($output);

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/doctor/fix'
                && $request->hasHeader('Accept', 'text/event-stream')
                && $request->data() === [
                    'mode' => $mode,
                    'families' => ['node'],
                    'node' => 'beast',
                    'compact_progress' => true,
                ]
            ),
        );

        expect($exitCode)
            ->toBe(0)
            ->and($frames)
            ->toHaveCount(2)
            ->and($frames[1]['event'])
            ->toBe('complete')
            ->and($frames[1]['success']['data']['doctor']['mode'])
            ->toBe($mode);
    })->with(['restore', 'adopt']);

    it('sends the configured default node when plain doctor has no explicit scope', function (): void {
        $store = new OrbitConfigStore(overridePath: base_path('tests/.tmp-doctor-default-node-config.json'));
        @unlink($store->path());
        $store->save(['defaults' => ['node' => 'default-app', 'profile' => null]]);
        app()->instance(OrbitConfigStore::class, $store);

        fakeDoctorRunStream(doctorRunCompleteStream(doctorVerifyReport([], [
            'node' => 'default-app',
        ])));

        [$exitCode] = runCommand($this, 'doctor', [
            '--family' => ['node'],
        ]);

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/doctor/run'
                && $request->hasHeader('Accept', 'text/event-stream')
                && $request->data() === [
                    'mode' => 'verify',
                    'families' => ['node'],
                    'node' => 'default-app',
                ]
            ),
        );

        expect($exitCode)->toBe(0);

        @unlink($store->path());
    });

    it('falls back to self scope when no default node is configured', function (): void {
        $store = new OrbitConfigStore(overridePath: base_path('tests/.tmp-doctor-empty-default-node-config.json'));
        @unlink($store->path());
        app()->instance(OrbitConfigStore::class, $store);

        fakeDoctorRunStream(doctorRunCompleteStream(doctorVerifyReport([], [
            'node' => 'caller',
            'self' => true,
        ])));

        [$exitCode] = runCommand($this, 'doctor', [
            '--family' => ['node'],
        ]);

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/doctor/run'
                && $request->data() === [
                    'mode' => 'verify',
                    'families' => ['node'],
                    'self' => true,
                ]
            ),
        );

        expect($exitCode)->toBe(0);

        @unlink($store->path());
    });

    it('keeps explicit self scope from being replaced by the configured default node', function (): void {
        $store = new OrbitConfigStore(overridePath: base_path('tests/.tmp-doctor-self-default-node-config.json'));
        @unlink($store->path());
        $store->save(['defaults' => ['node' => 'default-app', 'profile' => null]]);
        app()->instance(OrbitConfigStore::class, $store);

        fakeDoctorRunStream(doctorRunCompleteStream(doctorVerifyReport([], [
            'node' => 'caller',
            'self' => true,
        ])));

        [$exitCode] = runCommand($this, 'doctor', [
            '--self' => true,
            '--family' => ['node'],
        ]);

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/doctor/run'
                && $request->data() === [
                    'mode' => 'verify',
                    'families' => ['node'],
                    'self' => true,
                ]
            ),
        );

        expect($exitCode)->toBe(0);

        @unlink($store->path());
    });

    it('does not inject the configured default node for workspace scope without an explicit node', function (): void {
        $store = new OrbitConfigStore(overridePath: base_path('tests/.tmp-doctor-workspace-default-node-config.json'));
        @unlink($store->path());
        $store->save(['defaults' => ['node' => 'default-app', 'profile' => null]]);
        app()->instance(OrbitConfigStore::class, $store);

        fakeDoctorRunStream(doctorRunCompleteStream(doctorVerifyReport([], [
            'node' => 'caller',
            'workspace' => 'docs-api',
        ])));

        [$exitCode] = runCommand($this, 'doctor', [
            '--workspace' => 'docs-api',
            '--family' => ['workspace'],
        ]);

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/doctor/run'
                && $request->data() === [
                    'mode' => 'verify',
                    'families' => ['workspace'],
                    'workspace' => 'docs-api',
                ]
            ),
        );

        expect($exitCode)->toBe(0);

        @unlink($store->path());
    });

    it('sends explicit fleet scope only when --all is supplied', function (): void {
        fakeDoctorRunStream(doctorRunCompleteStream(doctorVerifyReport([], [
            'node' => null,
            'role' => 'fleet',
            'targets' => ['app-1', 'gateway-1'],
        ])));

        [$exitCode] = runCommand($this, 'doctor', [
            '--all' => true,
            '--family' => ['node'],
            '--stream-json' => true,
        ]);

        assertGatewayStreamSent(
            fn (FakeGatewayStreamRequest $request): bool => (
                $request->method() === 'POST'
                && $request->url() === 'https://gateway.test/api/doctor/run'
                && $request->hasHeader('Accept', 'text/event-stream')
                && $request->data() === [
                    'mode' => 'verify',
                    'families' => ['node'],
                    'all' => true,
                    'compact_progress' => true,
                ]
            ),
        );

        expect($exitCode)->toBe(0);
    });

    it('rejects --node=all before contacting the gateway', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--node' => 'all',
            '--json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['meta']['field'])
            ->toBe('node')
            ->and($decoded['error']['meta']['value'])
            ->toBe('all');
    });

    it('streams doctor terminal errors with the doctor payload as a JSON error sibling', function (): void {
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

        fakeDoctorRunStream(gatewayProgressFrame('tree', [
            'title' => 'Running Doctor',
            'steps' => [['key' => 'node', 'label' => 'Check node']],
        ]).gatewayProgressFrame('step', ['key' => 'node', 'status' => 'failed', 'message' => 'Drift detected'])
            .gatewayProgressFrame('error', [
                'exit_code' => 1,
                'message' => 'Doctor detected drift.',
                'data' => [
                    'code' => 'drift_detected',
                    'message' => 'Doctor detected drift.',
                    'meta' => [],
                    'data' => ['doctor' => $report],
                    'footer' => 'Doctor detected drift.',
                ],
            ]));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--node' => 'beast',
            '--family' => ['node'],
            '--stream-json' => true,
        ]);

        $frames = decodeDoctorNdjson($output);

        expect($exitCode)
            ->toBe(1)
            ->and($frames)
            ->toHaveCount(3)
            ->and($frames[2])
            ->toBe([
                'event' => 'error',
                'error' => [
                    'code' => 'drift_detected',
                    'message' => 'Doctor detected drift.',
                    'meta' => [],
                    'data' => ['doctor' => $report],
                ],
            ]);
    });

    it('writes valid file-captured fleet stream-json without NUL bytes or transport failures', function (): void {
        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);

        $report = doctorFleetReport();
        $report['healthy'] = false;
        $report['summary']['issues'] = 1;
        $report['issues'] = [[
            'family' => 'proxy',
            'node' => 'app-prod-1',
            'key' => 'proxy.node_probe_failed',
            'code' => 'proxy.node_probe_failed',
            'kind' => 'unverifiable',
            'summary' => 'Proxy node route scan failed on node app-prod-1.',
            'detail' => ['error' => 'RemoteShell failed on app-prod-1 (exit 1): (no output)'],
            'restorable' => false,
            'adoptable' => false,
        ]];

        app()->instance(StreamJsonIdleStepWriter::class, new class extends StreamJsonIdleStepWriter {
            /**
             * @param  callable(string): void  $write
             * @param  resource|null  $stdout
             */
            public function start(string $line, callable $write, int $intervalSeconds = 1, mixed $stdout = null): void
            {
                $write($line);
            }

            public function stop(): void {}
        });
        app()->forgetInstance(GatewayStreamClient::class);
        app()->instance(GatewayStreamClient::class, new class($report) {
            public function __construct(
                private readonly array $report,
            ) {}

            /**
             * @param  array<string, mixed>  $payload
             * @param  callable(ProgressEventType, array<string, mixed>): void  $onEvent
             */
            public function streamEvents(
                string $path,
                array $payload,
                callable $onEvent,
                string $method = 'post',
                ?callable $onIdle = null,
                int $idleIntervalMicroseconds = 300_000,
            ): int {
                $onEvent(ProgressEventType::Tree, [
                    'title' => 'Running Doctor',
                    'steps' => [
                        ['key' => 'app-dev-1', 'label' => 'Check app-dev-1'],
                        ['key' => 'app-prod-1', 'label' => 'Check app-prod-1'],
                    ],
                ]);
                $onEvent(ProgressEventType::Step, [
                    'key' => 'app-prod-1',
                    'status' => 'running',
                    'message' => 'Checking app-prod-1',
                ]);
                $onEvent(ProgressEventType::Step, [
                    'key' => 'app-prod-1',
                    'status' => 'done',
                    'message' => 'app-prod-1 checked',
                ]);
                $onEvent(ProgressEventType::Error, [
                    'exit_code' => 1,
                    'message' => 'Doctor detected drift.',
                    'data' => [
                        'code' => 'drift_detected',
                        'message' => 'Doctor detected drift.',
                        'meta' => [],
                        'data' => ['doctor' => $this->report],
                        'footer' => 'Doctor detected drift.',
                    ],
                ]);

                return 1;
            }
        });

        [$exitCode, $output] = runDecoratedCommand($this, 'doctor', [
            '--all' => true,
            '--stream-json' => true,
        ]);

        expect($output)->not->toContain("\0")->and(ltrim($output, "\0"))->toBe($output);

        $lines = array_values(array_filter(explode("\n", rtrim($output, "\n"))));

        foreach ($lines as $line) {
            json_decode($line, associative: true, flags: JSON_THROW_ON_ERROR);
        }

        $terminal = json_decode(end($lines), associative: true, flags: JSON_THROW_ON_ERROR);

        $frames = decodeDoctorNdjson($output);
        $runningFrames = array_values(array_filter(
            $frames,
            static fn (array $frame): bool => (
                ($frame['event'] ?? null) === 'step'
                && ($frame['data']['key'] ?? null) === 'app-prod-1'
                && ($frame['data']['status'] ?? null) === 'running'
            ),
        ));

        expect($exitCode)
            ->toBe(1)
            ->and(count($runningFrames))
            ->toBeGreaterThanOrEqual(2)
            ->and($terminal['event'])
            ->toBe('error')
            ->and($terminal['error']['code'])
            ->toBe('drift_detected')
            ->and($output)
            ->not->toContain('gateway_unavailable');
    });

    it('replays the last running step during stream-json idle waits', function (): void {
        config()->set('orbit.gateway.url', 'https://gateway.test');
        config()->set('orbit.gateway.timeout', 30);
        app()->instance(StreamJsonIdleStepWriter::class, new class extends StreamJsonIdleStepWriter {
            /**
             * @param  callable(string): void  $write
             * @param  resource|null  $stdout
             */
            public function start(string $line, callable $write, int $intervalSeconds = 1, mixed $stdout = null): void
            {
                $write($line);
            }

            public function stop(): void {}
        });
        app()->forgetInstance(GatewayStreamClient::class);
        app()->instance(GatewayStreamClient::class, new class {
            /**
             * @param  array<string, mixed>  $payload
             * @param  callable(ProgressEventType, array<string, mixed>): void  $onEvent
             */
            public function streamEvents(
                string $path,
                array $payload,
                callable $onEvent,
                string $method = 'post',
                ?callable $onIdle = null,
                int $idleIntervalMicroseconds = 300_000,
            ): int {
                $onEvent(ProgressEventType::Step, [
                    'key' => 'beast',
                    'status' => 'running',
                    'message' => 'Checking beast',
                ]);

                $onEvent(ProgressEventType::Complete, [
                    'exit_code' => 0,
                    'data' => ['doctor' => doctorFleetReport()],
                ]);

                return 0;
            }
        });

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--all' => true,
            '--stream-json' => true,
        ]);

        $frames = decodeDoctorNdjson($output);
        $runningFrames = array_values(array_filter(
            $frames,
            static fn (array $frame): bool => (
                ($frame['event'] ?? null) === 'step'
                && ($frame['data']['key'] ?? null) === 'beast'
                && ($frame['data']['status'] ?? null) === 'running'
            ),
        ));

        expect($exitCode)
            ->toBe(0)
            ->and($runningFrames)
            ->toHaveCount(2)
            ->and($frames[count($frames) - 1]['event'])
            ->toBe('complete');
    });

    it('streams transport failures as error frames after progress has started', function (): void {
        fakeDoctorRunStream(gatewayProgressFrame('tree', [
            'title' => 'Running Doctor',
            'steps' => [['key' => 'node', 'label' => 'Check node']],
        ]));

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--node' => 'beast',
            '--family' => ['node'],
            '--stream-json' => true,
        ]);

        $frames = decodeDoctorNdjson($output);

        expect($exitCode)
            ->toBe(1)
            ->and($frames)
            ->toHaveCount(2)
            ->and($frames[0]['event'])
            ->toBe('tree')
            ->and($frames[1]['event'])
            ->toBe('error')
            ->and($frames[1]['error']['code'])
            ->toBe('gateway_unavailable')
            ->and($frames[1]['error']['message'])
            ->toBe('Gateway progress stream closed without a terminal frame.');
    });

    it('rejects ambiguous doctor JSON renderers before contacting the gateway', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--json' => true,
            '--stream-json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['message'])
            ->toBe('doctor --json and --stream-json cannot be combined.')
            ->and($decoded['error']['meta']['fields'])
            ->toBe(['json', 'stream-json']);
    });

    it('rejects interactive doctor fix mode with stream JSON before contacting the gateway', function (): void {
        Http::fake();

        [$exitCode, $output] = runCommand($this, 'doctor', [
            '--fix' => true,
            '--stream-json' => true,
        ]);

        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        Http::assertNothingSent();

        expect($exitCode)
            ->toBe(1)
            ->and($decoded['error']['code'])
            ->toBe('validation_failed')
            ->and($decoded['error']['message'])
            ->toBe('doctor --fix cannot run with --stream-json because it requires interactive prompts.')
            ->and($decoded['error']['meta']['field'])
            ->toBe('stream-json');
    });
});

<?php

declare(strict_types=1);

use App\Services\GatewayApiClient;
use App\Services\GatewayOperationEventStreamClient;
use App\Services\GatewayOperationFollower;
use App\Services\Updates\RunsLocalUpdate;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Progress\ProgressEventType;
use Orbit\Core\Progress\VirtualTerminalScreen;

require_once dirname(__DIR__, 3).'/Support/update_all_liveness_cadence.php';

beforeEach(function (): void {
    $this->localUpdater = new UpdateAllCommandFakeUpdater;

    app()->instance(RunsLocalUpdate::class, $this->localUpdater);
});

it('starts the durable update operation and follows its event stream in json mode', function (): void {
    $follower = new UpdateAllCommandFakeFollower([
        ['type' => ProgressEventType::Step, 'payload' => ['message' => 'runner started']],
        ['type' => ProgressEventType::Complete, 'payload' => ['exit_code' => 0, 'data' => ['updates' => []]]],
    ]);
    fakeGateway(fakeUpdateAllStartEnvelope());
    app()->instance(GatewayOperationFollower::class, $follower);

    [$exitCode, $output] = runCommand($this, 'update:all', [
        '--json' => true,
    ]);

    $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://gateway.test/api/update/all/start'
        && $request->data() === []);

    expect($exitCode)->toBe(0)
        // local update runs AFTER gateway in json mode; uses downloadBinary/replaceBinary/runDoctor
        ->and($this->localUpdater->calls)->toBe([
            'download',
            'replace',
            'doctor',
        ])
        ->and($follower->eventsUrls)->toBe(['/api/operations/run-1/events'])
        ->and($decoded)->toBe([
            'event' => 'complete',
            'data' => ['exit_code' => 0, 'data' => ['updates' => []]],
        ])
        ->and($output)->not->toContain('runner started');
});

it('reports partial failure in json mode when the local update fails after the gateway phase', function (): void {
    $this->localUpdater->results['download'] = [
        'successful' => false,
        'exit_code' => 1,
        'output' => 'local binary update failed',
        'staged_path' => null,
        'version' => null,
    ];

    $follower = new UpdateAllCommandFakeFollower([
        ['type' => ProgressEventType::Complete, 'payload' => ['exit_code' => 0, 'data' => ['updates' => []], 'target_version' => '1.2.3']],
    ]);
    fakeGateway(fakeUpdateAllStartEnvelope());
    app()->instance(GatewayOperationFollower::class, $follower);

    [$exitCode, $output] = runCommand($this, 'update:all', [
        '--json' => true,
    ]);

    $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

    // Gateway WAS started (gateway-first ordering)
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://gateway.test/api/update/all/start');

    expect($exitCode)->toBe(1)
        ->and($decoded['error']['code'])->toBe('local_update_failed')
        ->and($decoded['error']['message'])->toBe('Failed to update local Orbit checkout.');
});

it('captures alternating local-row spinner frames from a pseudo-tty before replace completes', function (): void {
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill') || ! function_exists('posix_getppid') || ! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
        $this->markTestSkipped('pcntl_fork, posix_kill, posix_getppid, pcntl_async_signals, and pcntl_signal are required to drive parent-process progress ticks during blocking work.');
    }

    $scriptBinary = findPseudoTtyScriptBinary();

    if ($scriptBinary === null) {
        $this->markTestSkipped('The script(1) binary is required to allocate a pseudo-tty for live progress capture.');
    }

    $captureScript = writeUpdateAllLivenessCaptureScript(base_path(), 6_000_000);
    $typescriptPath = sys_get_temp_dir().'/orbit-update-all-pty-'.uniqid('', true).'.typescript';
    $pendingTranscriptPath = sys_get_temp_dir().'/orbit-update-all-pending-'.uniqid('', true).'.typescript';

    $command = pseudoTtyWrappedCommand($scriptBinary, $typescriptPath, [
        PHP_BINARY,
        $captureScript,
    ]);

    $process = proc_open(
        $command,
        [
            ['pipe', 'r'],
            ['pipe', 'w'],
            ['pipe', 'w'],
        ],
        $pipes,
        base_path(),
    );

    if (! is_resource($process)) {
        @unlink($captureScript);
        @unlink($typescriptPath);
        @unlink($pendingTranscriptPath);

        throw new RuntimeException('Could not start the pseudo-tty update:all capture process.');
    }

    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $pendingTranscript = '';
    $observedStates = [];
    $targetRow = null;
    $rowIdentityStable = true;
    $capturedWhileRunning = false;
    $cadenceState = [
        'anchor_us' => null,
        'anchor_spinner' => null,
        'first_transition_us' => -1,
        'last_spinner' => null,
    ];
    $deadline = microtime(true) + 15.0;
    $processPid = proc_get_status($process)['pid'] ?? null;

    try {
        while (microtime(true) < $deadline) {
            $stillRunning = is_int($processPid)
                && function_exists('posix_kill')
                && posix_kill($processPid, 0);

            clearstatcache(true, $typescriptPath);

            if ($stillRunning && is_readable($typescriptPath)) {
                $capture = file_get_contents($typescriptPath) ?: '';
                $observation = updateAllPendingPtySpinnerState($capture);
                $nowUs = updateAllLivenessNowUs();

                if ($observation !== null) {
                    if ($targetRow !== null && $targetRow !== $observation['row']) {
                        $rowIdentityStable = false;
                    }

                    $targetRow ??= $observation['row'];
                    $observedStates[$observation['spinner']] = true;

                    if ($rowIdentityStable) {
                        updateAllLivenessObserveSpinner($cadenceState, $observation['spinner'], $nowUs);
                    }

                    if ($rowIdentityStable
                        && isset($observedStates[VirtualTerminalScreen::SPINNER_CYAN_OPEN])
                        && isset($observedStates[VirtualTerminalScreen::SPINNER_CYAN_FILLED])) {
                        $pendingTranscript = $capture;
                        file_put_contents($pendingTranscriptPath, $pendingTranscript);
                        $capturedWhileRunning = true;

                        break;
                    }
                }
            }

            if (! $stillRunning) {
                break;
            }

            usleep(10_000);
        }
    } finally {
        if (proc_get_status($process)['running'] ?? false) {
            proc_terminate($process);
        }

        proc_close($process);

        @unlink($captureScript);
        @unlink($typescriptPath);
        @unlink($pendingTranscriptPath);
    }

    $cadence = validateUpdateAllLivenessCadence($cadenceState['first_transition_us']);

    expect($capturedWhileRunning)->toBeTrue(sprintf(
        'Expected both spinner frames on the same local Replacing cli binary virtual-screen row while the delayed replace step was still running; row=%s stable=%s states=[%s] first_transition_us=%s cadence_ok=%s.',
        $targetRow === null ? 'none' : (string) $targetRow,
        $rowIdentityStable ? 'true' : 'false',
        implode(',', array_keys($observedStates)),
        $cadenceState['first_transition_us'] < 0 ? 'none' : (string) $cadenceState['first_transition_us'],
        $cadence['cadence_ok'] ? 'true' : 'false',
    ))
        ->and($pendingTranscript)->not->toBe('')
        ->and($cadenceState['first_transition_us'])->toBeGreaterThanOrEqual(250_000)
        ->and($cadence['cadence_ok'])->toBeTrue($cadence['reason'] ?? 'spinner cadence was invalid');
});

it('keeps update-all rows blinking while gateway start and event stream are quiet', function (): void {
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill') || ! function_exists('posix_getppid') || ! function_exists('pcntl_async_signals') || ! function_exists('pcntl_signal')) {
        $this->markTestSkipped('pcntl_fork, posix_kill, posix_getppid, pcntl_async_signals, and pcntl_signal are required to drive parent-process progress ticks during blocking work.');
    }

    $scriptBinary = findPseudoTtyScriptBinary();

    if ($scriptBinary === null) {
        $this->markTestSkipped('The script(1) binary is required to allocate a pseudo-tty for live progress capture.');
    }

    $port = unusedUpdateAllGatewayLivenessPort();
    $router = startUpdateAllGatewayLivenessRouter(
        port: $port,
        startDelayMicroseconds: 1_500_000,
        silentDelayMicroseconds: 1_500_000,
    );
    $captureScript = writeUpdateAllGatewayLivenessCaptureScript(base_path(), "http://127.0.0.1:{$port}");
    $typescriptPath = sys_get_temp_dir().'/orbit-update-all-gateway-pty-'.uniqid('', true).'.typescript';
    $command = pseudoTtyWrappedCommand($scriptBinary, $typescriptPath, [
        PHP_BINARY,
        $captureScript,
    ]);

    $process = proc_open(
        $command,
        [
            ['pipe', 'r'],
            ['pipe', 'w'],
            ['pipe', 'w'],
        ],
        $pipes,
        base_path(),
    );

    if (! is_resource($process)) {
        stopUpdateAllGatewayLivenessRouter($router);
        @unlink($captureScript);
        @unlink($typescriptPath);

        throw new RuntimeException('Could not start the pseudo-tty update:all gateway liveness process.');
    }

    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $checking = newUpdateAllPtyTargetState();
    $gateway = newUpdateAllPtyTargetState();
    $deadline = microtime(true) + 12.0;
    $timedOut = true;

    try {
        while (microtime(true) < $deadline) {
            $stillRunning = proc_get_status($process)['running'] ?? false;

            clearstatcache(true, $typescriptPath);

            if (is_readable($typescriptPath)) {
                $capture = file_get_contents($typescriptPath) ?: '';
                recordUpdateAllPtyTargetState(
                    $checking,
                    updateAllPtySpinnerState($capture, 'Checking for updates', 'Checking'),
                    $capture,
                );
                recordUpdateAllPtyTargetState(
                    $gateway,
                    updateAllPtySpinnerState($capture, 'gateway', 'Replacing cli binary'),
                    $capture,
                );
            }

            if (! $stillRunning) {
                $timedOut = false;

                break;
            }

            usleep(10_000);
        }
    } finally {
        if (proc_get_status($process)['running'] ?? false) {
            proc_terminate($process);
        }

        proc_close($process);
        stopUpdateAllGatewayLivenessRouter($router);

        @unlink($captureScript);
        @unlink($typescriptPath);
    }

    $checkingCadence = validateUpdateAllLivenessCadence($checking['cadence_state']['first_transition_us']);
    $gatewayCadence = validateUpdateAllLivenessCadence($gateway['cadence_state']['first_transition_us']);

    expect($timedOut)->toBeFalse('The pseudo-tty update:all gateway liveness command did not finish.')
        ->and($checking['captured'])->toBeTrue(sprintf(
            'Expected Checking for updates to alternate while the gateway start POST was delayed; row=%s stable=%s states=[%s] first_transition_us=%s.',
            $checking['target_row'] === null ? 'none' : (string) $checking['target_row'],
            $checking['row_identity_stable'] ? 'true' : 'false',
            implode(',', array_keys($checking['observed_states'])),
            $checking['cadence_state']['first_transition_us'] < 0 ? 'none' : (string) $checking['cadence_state']['first_transition_us'],
        ))
        ->and($checkingCadence['cadence_ok'])->toBeTrue($checkingCadence['reason'] ?? 'checking spinner cadence was invalid')
        ->and($checking['cadence_state']['first_transition_us'])->toBeLessThan(900_000)
        ->and($gateway['captured'])->toBeTrue(sprintf(
            'Expected gateway to alternate while the event stream was quiet; row=%s stable=%s states=[%s] first_transition_us=%s.',
            $gateway['target_row'] === null ? 'none' : (string) $gateway['target_row'],
            $gateway['row_identity_stable'] ? 'true' : 'false',
            implode(',', array_keys($gateway['observed_states'])),
            $gateway['cadence_state']['first_transition_us'] < 0 ? 'none' : (string) $gateway['cadence_state']['first_transition_us'],
        ))
        ->and($gatewayCadence['cadence_ok'])->toBeTrue($gatewayCadence['reason'] ?? 'gateway spinner cadence was invalid')
        ->and($gateway['cadence_state']['first_transition_us'])->toBeLessThan(900_000);
});

it('aligns check-row settled status with node stage columns', function (): void {
    fakeGateway(fakeUpdateAllStartEnvelope());
    app()->instance(GatewayOperationFollower::class, new UpdateAllCommandFakeFollower([
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-updates', 'status' => 'done', 'message' => 'Done: latest version is 1.2.3']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-fleet-versions', 'status' => 'done', 'message' => 'Done: 1 outdated node found', 'update_targets' => ['gateway', 'local', 'beast']]],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'gateway', 'status' => 'done', 'message' => '']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.beast', 'status' => 'running', 'message' => 'Replacing cli binary']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.beast', 'status' => 'done', 'message' => 'Workload node beast updated']],
        ['type' => ProgressEventType::Complete, 'payload' => ['status' => 'succeeded', 'target_version' => '1.2.3']],
    ]));

    [$exitCode, $output] = runCommand($this, 'update:all');

    $lines = array_values(array_filter(explode("\n", stripDecoratedLines($output))));
    $columns = [];

    foreach ([
        ['Done: latest version is 1.2.3', 'Done:'],
        ['Done: 1 outdated node found', 'Done:'],
        ['beast', 'Replacing'],
        ['local', 'Replacing'],
    ] as [$needle, $statusNeedle]) {
        $line = findStrippedProgressLine($lines, $needle, $statusNeedle);

        expect($line)->not->toBeNull();

        $columns[] = strpos($line, $statusNeedle);
    }

    expect($exitCode)->toBe(0)
        ->and(array_values(array_unique($columns)))->toHaveCount(1);
});

it('renders initial check rows before gateway stream events arrive', function (): void {
    fakeGateway(fakeUpdateAllStartEnvelope());
    app()->instance(GatewayOperationFollower::class, new UpdateAllCommandFakeFollower([
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'gateway', 'status' => 'done', 'message' => '']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.agent', 'status' => 'done', 'message' => 'Workload node agent updated']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.beast', 'status' => 'done', 'message' => 'Workload node beast updated']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.cache', 'status' => 'done', 'message' => 'Workload node cache updated']],
        ['type' => ProgressEventType::Complete, 'payload' => ['status' => 'succeeded', 'target_version' => '1.2.3']],
    ]));

    [$exitCode, $output] = runCommand($this, 'update:all');

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Updating Orbit nodes')
        ->and($output)->toContain('│')
        ->and($output)->toContain('Checking for updates')
        ->and($output)->toContain('Checking fleet versions')
        ->and($output)->toContain('└');
});

it('renders every workload node from the gateway stream fixture', function (): void {
    fakeGateway(fakeUpdateAllStartEnvelope());
    app()->instance(GatewayOperationFollower::class, new UpdateAllCommandFakeFollower([
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-updates', 'status' => 'done', 'message' => 'Done: latest version is 1.2.3']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-fleet-versions', 'status' => 'done', 'message' => 'Done: 3 outdated nodes found', 'update_targets' => ['gateway', 'local', 'agent', 'beast', 'cache']]],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'gateway', 'status' => 'done', 'message' => '']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.agent', 'status' => 'done', 'message' => 'Workload node agent updated']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.beast', 'status' => 'done', 'message' => 'Workload node beast updated']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.cache', 'status' => 'done', 'message' => 'Workload node cache updated']],
        ['type' => ProgressEventType::Complete, 'payload' => ['status' => 'succeeded', 'target_version' => '1.2.3']],
    ]));

    [$exitCode, $output] = runCommand($this, 'update:all');

    expect($exitCode)->toBe(0)
        ->and($output)->toMatch('/gateway\s+Done/')
        ->and($output)->toMatch('/agent\s+Done/')
        ->and($output)->toMatch('/beast\s+Done/')
        ->and($output)->toMatch('/cache\s+Done/')
        ->and($output)->toMatch('/local\s+Done/');
});

it('rejects the legacy final-only node summary shape in human mode', function (): void {
    fakeGateway(fakeUpdateAllStartEnvelope());
    app()->instance(GatewayOperationFollower::class, new UpdateAllCommandFakeFollower([
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-updates', 'status' => 'done', 'message' => 'Done: latest version is 1.2.3']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-fleet-versions', 'status' => 'done', 'message' => 'Done: 2 outdated nodes found', 'update_targets' => ['gateway', 'local', 'agent', 'beast']]],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'gateway', 'status' => 'done', 'message' => '']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.agent', 'status' => 'done', 'message' => 'Workload node agent updated']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.beast', 'status' => 'done', 'message' => 'Workload node beast updated']],
        ['type' => ProgressEventType::Complete, 'payload' => ['status' => 'succeeded', 'target_version' => '1.2.3']],
    ]));

    [$exitCode, $output] = runCommand($this, 'update:all');

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('│')
        ->and($output)->toContain('Checking for updates')
        ->and($output)->toContain('Checking fleet versions')
        ->and($output)->toContain('Success: All nodes are running on version 1.2.3')
        ->and($output)->not->toContain('Successfully updated 2 nodes')
        ->and($output)->not->toMatch('/^local\s+Done$/m')
        ->and($output)->not->toMatch('/^gateway\s+Done$/m');
});

it('renders update-all target progress in human mode', function (): void {
    fakeGateway(fakeUpdateAllStartEnvelope());
    app()->instance(GatewayOperationFollower::class, new UpdateAllCommandFakeFollower([
        ['type' => ProgressEventType::Tree, 'payload' => ['title' => 'Update all', 'steps' => [['label' => 'Update gateway']]]],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-updates', 'status' => 'running', 'message' => 'Checking']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-updates', 'status' => 'done', 'message' => 'Done: latest version is 1.2.3']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-fleet-versions', 'status' => 'running', 'message' => 'Checking']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-fleet-versions', 'status' => 'done', 'message' => 'Done: 2 outdated nodes found', 'update_targets' => ['gateway', 'local', 'agent', 'beast']]],
        ['type' => ProgressEventType::Step, 'payload' => ['message' => 'Fleet update lease acquired']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'gateway', 'status' => 'running', 'message' => 'Downloading 1.2.3 assets']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'gateway', 'status' => 'running', 'message' => 'Updating gateway app']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'gateway', 'status' => 'running', 'message' => 'Replacing cli binary']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'gateway', 'status' => 'running', 'message' => 'Running doctor']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'gateway', 'status' => 'done', 'message' => '']],
        // Workload sub-steps
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.agent', 'status' => 'running', 'message' => 'Downloading 1.2.3']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.agent', 'status' => 'running', 'message' => 'Replacing cli binary']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.agent', 'status' => 'running', 'message' => 'Running doctor']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.agent', 'status' => 'done', 'message' => 'Workload node agent updated (2 issues)']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.beast', 'status' => 'done', 'message' => 'Workload node beast skipped: already up to date']],
        ['type' => ProgressEventType::Step, 'payload' => ['message' => 'Verifying fleet update']],
        ['type' => ProgressEventType::Step, 'payload' => ['message' => 'Fleet update verified']],
        // local sub-steps arrive after the gateway phase
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.local', 'status' => 'running', 'message' => 'Downloading 1.2.3']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.local', 'status' => 'running', 'message' => 'Replacing cli binary']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.local', 'status' => 'running', 'message' => 'Running doctor']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.local', 'status' => 'done', 'message' => 'Local node updated']],
        ['type' => ProgressEventType::Complete, 'payload' => [
            'status' => 'succeeded',
            'target_version' => '1.2.3',
            'manifest_version' => '1.2.3',
        ]],
    ]));

    [$exitCode, $output] = runCommand($this, 'update:all');

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Updating Orbit nodes')
        ->and($output)->toContain('│')
        // No preamble local row before gateway
        ->and($output)->not->toMatch('/local\s+Updating CLI/')
        ->and($output)->toMatch('/Checking for updates\s+Checking\b/')
        ->and($output)->toMatch('/Checking fleet versions\s+Checking\b/')
        ->and($output)->toMatch('/Checking for updates\s+Done: latest version is 1.2.3/')
        ->and($output)->toMatch('/Checking fleet versions\s+Done: 2 outdated nodes found/')
        ->and($output)->toMatch('/gateway\s+Downloading 1\.2\.3 assets/')
        ->and($output)->toMatch('/gateway\s+Updating gateway app/')
        ->and($output)->toMatch('/gateway\s+Replacing cli binary/')
        ->and($output)->toMatch('/gateway\s+Running doctor/')
        ->and($output)->toMatch('/gateway\s+Done/')
        ->and($output)->toMatch('/local\s+Waiting\b/')
        ->and($output)->toMatch('/agent\s+Done \(2 issues\)/')
        ->and($output)->toMatch('/beast\s+Skipped: already up to date/')
        ->and($output)->toMatch('/local\s+Done/')
        ->and($output)->toContain('Success: All nodes are running on version 1.2.3')
        ->and($output)->not->toContain('[tree]')
        ->and($output)->not->toContain('[step]')
        ->and($output)->not->toContain('status: succeeded')
        ->and($output)->not->toContain('"success"');
});

it('renders all-current short-circuit footer when 0 outdated nodes', function (): void {
    fakeGateway(fakeUpdateAllStartEnvelope());
    app()->instance(GatewayOperationFollower::class, new UpdateAllCommandFakeFollower([
        // Operation-level + fleet-lease events precede the checks and must not
        // create a spurious gateway row in the short-circuit.
        ['type' => ProgressEventType::Step, 'payload' => ['message' => 'Update runner started']],
        ['type' => ProgressEventType::Step, 'payload' => ['message' => 'Fleet update lease acquired']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-updates', 'status' => 'running', 'message' => 'Checking']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-updates', 'status' => 'done', 'message' => 'Done: latest version is 1.2.3']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-fleet-versions', 'status' => 'running', 'message' => 'Checking']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-fleet-versions', 'status' => 'done', 'message' => 'Done: all nodes running on 1.2.3']],
        ['type' => ProgressEventType::Complete, 'payload' => [
            'status' => 'skipped',
            'target_version' => '1.2.3',
            'manifest_version' => '1.2.3',
            'skipped' => true,
        ]],
    ]));

    [$exitCode, $output] = runCommand($this, 'update:all');

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Updating Orbit nodes')
        ->and($output)->toContain('│')
        ->and($output)->toMatch('/Checking for updates\s+Checking\b/')
        ->and($output)->toMatch('/Checking fleet versions\s+Checking\b/')
        ->and($output)->toMatch('/Checking for updates\s+Done: latest version is 1.2.3/')
        ->and($output)->toMatch('/Checking fleet versions\s+Done: all nodes running on 1.2.3/')
        // No gateway/local/workload rows appear
        ->and($output)->not->toMatch('/gateway\s+/')
        ->and($output)->not->toMatch('/local\s+/')
        ->and($output)->not->toMatch('/\bagent\s+/')
        ->and($output)->not->toMatch('/\bbeast\s+/')
        ->and($output)->toContain('Skipped: 1.2.3 is already installed on all nodes');
});

it('renders per-node sub-stages for workload nodes', function (): void {
    fakeGateway(fakeUpdateAllStartEnvelope());
    app()->instance(GatewayOperationFollower::class, new UpdateAllCommandFakeFollower([
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-updates', 'status' => 'done', 'message' => 'Done: latest version is 1.2.3']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-fleet-versions', 'status' => 'done', 'message' => 'Done: 1 outdated node found', 'update_targets' => ['gateway', 'local', 'beast']]],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'gateway', 'status' => 'done', 'message' => '']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.beast', 'status' => 'running', 'message' => 'Downloading 1.2.3']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.beast', 'status' => 'running', 'message' => 'Replacing cli binary']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.beast', 'status' => 'running', 'message' => 'Running doctor']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.beast', 'status' => 'done', 'message' => 'Workload node beast updated']],
        ['type' => ProgressEventType::Complete, 'payload' => ['status' => 'succeeded', 'target_version' => '1.2.3']],
    ]));

    [$exitCode, $output] = runCommand($this, 'update:all');

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Updating Orbit nodes')
        ->and($output)->toContain('│')
        ->and($output)->toMatch('/beast\s+Downloading 1\.2\.3/')
        ->and($output)->toMatch('/beast\s+Replacing cli binary/')
        ->and($output)->toMatch('/beast\s+Running doctor/')
        ->and($output)->toMatch('/beast\s+Done/');
});

it('renders the initial idle tree with a spacer before every row and the footer', function (): void {
    fakeGateway(fakeUpdateAllStartEnvelope());
    app()->instance(GatewayOperationFollower::class, new UpdateAllCommandFakeFollower([
        ['type' => ProgressEventType::Complete, 'payload' => ['status' => 'skipped', 'target_version' => '1.2.3', 'skipped' => true]],
    ]));

    [$exitCode, $output] = runCommand($this, 'update:all');

    expect($exitCode)->toBe(0)
        ->and($output)->toMatch('/Updating Orbit nodes.*│.*Checking for updates.*│.*Checking fleet versions.*│.*Working\.\.\./s');
});

it('reveals fan-out rows after outdated fleet check before any gateway step in human mode', function (): void {
    fakeGateway(fakeUpdateAllStartEnvelope());
    app()->instance(GatewayOperationFollower::class, new UpdateAllCommandFakeFollower([
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-updates', 'status' => 'done', 'message' => 'Done: latest version is 1.2.3']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-fleet-versions', 'status' => 'done', 'message' => 'Done: 2 outdated nodes found', 'update_targets' => ['gateway', 'local', 'beast', 'agent']]],
        ['type' => ProgressEventType::Error, 'payload' => ['message' => 'Update aborted']],
    ]));

    [$exitCode, $output] = runCommand($this, 'update:all');

    expect($exitCode)->toBe(1)
        ->and($output)->toMatch('/local\s+Waiting\b/')
        ->and($output)->toMatch('/beast\s+Waiting\b/')
        ->and($output)->toMatch('/agent\s+Waiting\b/')
        ->and($output)->not->toMatch('/gateway\s+Downloading/')
        ->and($output)->not->toMatch('/gateway\s+Done/');

    assertProgressTargetOrder($output, ['gateway', 'local', 'beast', 'agent']);
});

it('keeps workload rows on Waiting while the gateway row is active in human mode', function (): void {
    fakeGateway(fakeUpdateAllStartEnvelope());
    app()->instance(GatewayOperationFollower::class, new UpdateAllCommandFakeFollower([
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-updates', 'status' => 'done', 'message' => 'Done: latest version is 1.2.3']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-fleet-versions', 'status' => 'done', 'message' => 'Done: 2 outdated nodes found', 'update_targets' => ['gateway', 'local', 'agent', 'beast']]],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.beast', 'status' => 'running', 'message' => 'Downloading 1.2.3']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'gateway', 'status' => 'running', 'message' => 'Replacing cli binary']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'gateway', 'status' => 'fail', 'message' => 'Gateway health failed']],
        ['type' => ProgressEventType::Error, 'payload' => ['message' => 'Gateway health failed']],
    ]));

    [$exitCode, $output] = runCommand($this, 'update:all');

    expect($exitCode)->toBe(1)
        ->and($output)->toMatch('/gateway\s+Replacing cli binary/')
        ->and($output)->toMatch('/local\s+Waiting\b/')
        ->and($output)->toMatch('/beast\s+Waiting\b/')
        ->and($output)->not->toMatch('/beast\s+Downloading 1\.2\.3/');
});

it('shows parallel fan-out rows with distinct active sub-stages in human mode', function (): void {
    fakeGateway(fakeUpdateAllStartEnvelope());
    app()->instance(GatewayOperationFollower::class, new UpdateAllCommandFakeFollower([
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-fleet-versions', 'status' => 'done', 'message' => 'Done: 2 outdated nodes found', 'update_targets' => ['gateway', 'local', 'agent', 'beast']]],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'gateway', 'status' => 'done', 'message' => '']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.agent', 'status' => 'running', 'message' => 'Downloading 1.2.3']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.beast', 'status' => 'running', 'message' => 'Replacing cli binary']],
        ['type' => ProgressEventType::Complete, 'payload' => ['status' => 'succeeded', 'target_version' => '1.2.3']],
    ]));

    [$exitCode, $output] = runCommand($this, 'update:all');

    expect($exitCode)->toBe(0)
        ->and($output)->toMatch('/agent\s+Downloading 1\.2\.3/')
        ->and($output)->toMatch('/beast\s+Replacing cli binary/');
});

it('renders every settled fan-out node row after workloads finish out of order in human mode', function (): void {
    fakeGateway(fakeUpdateAllStartEnvelope());
    app()->instance(GatewayOperationFollower::class, new UpdateAllCommandFakeFollower([
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-fleet-versions', 'status' => 'done', 'message' => 'Done: 2 outdated nodes found', 'update_targets' => ['gateway', 'local', 'beast', 'agent']]],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'gateway', 'status' => 'done', 'message' => '']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.beast', 'status' => 'done', 'message' => 'Workload node beast updated']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.agent', 'status' => 'done', 'message' => 'Workload node agent updated']],
        ['type' => ProgressEventType::Complete, 'payload' => ['status' => 'succeeded', 'target_version' => '1.2.3']],
    ]));

    [$exitCode, $output] = runCommand($this, 'update:all');

    expect($exitCode)->toBe(0)
        ->and($output)->toMatch('/gateway\s+Done/')
        ->and($output)->toMatch('/local\s+Done/')
        ->and($output)->toMatch('/agent\s+Done/')
        ->and($output)->toMatch('/beast\s+Done/');

    assertProgressTargetOrder($output, ['gateway', 'local', 'beast', 'agent']);
});

it('renders the Updating Orbit nodes title in human mode', function (): void {
    fakeGateway(fakeUpdateAllStartEnvelope());
    app()->instance(GatewayOperationFollower::class, new UpdateAllCommandFakeFollower([
        ['type' => ProgressEventType::Complete, 'payload' => ['status' => 'skipped', 'target_version' => '1.2.3', 'skipped' => true]],
    ]));

    [$exitCode, $output] = runCommand($this, 'update:all');

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('Updating Orbit nodes');
});

it('keeps fan-out rows on Waiting and fails the footer when the gateway row fails in human mode', function (): void {
    fakeGateway(fakeUpdateAllStartEnvelope());
    app()->instance(GatewayOperationFollower::class, new UpdateAllCommandFakeFollower([
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-updates', 'status' => 'done', 'message' => 'Done: latest version is 1.2.3']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-fleet-versions', 'status' => 'done', 'message' => 'Done: 2 outdated nodes found', 'update_targets' => ['gateway', 'local', 'agent', 'beast']]],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.beast', 'status' => 'running', 'message' => 'Downloading 1.2.3']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'gateway', 'status' => 'fail', 'message' => 'Gateway health failed']],
        ['type' => ProgressEventType::Error, 'payload' => ['message' => 'Gateway health failed']],
    ]));

    [$exitCode, $output] = runCommand($this, 'update:all');

    expect($exitCode)->toBe(1)
        ->and($output)->toMatch('/gateway\s+Failed\b.*Gateway health failed/')
        ->and($output)->toMatch('/local\s+Waiting\b/')
        ->and($output)->toMatch('/beast\s+Waiting\b/')
        ->and($output)->not->toMatch('/beast\s+Downloading 1\.2\.3/')
        ->and($output)->toContain('Failed')
        ->and($this->localUpdater->calls)->toBe([]);
});

it('succeeds with a success footer when only doctor issue counts are reported', function (): void {
    $this->localUpdater->doctorIssues = 2;

    fakeGateway(fakeUpdateAllStartEnvelope());
    app()->instance(GatewayOperationFollower::class, new UpdateAllCommandFakeFollower([
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-updates', 'status' => 'done', 'message' => 'Done: latest version is 1.2.3']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-fleet-versions', 'status' => 'done', 'message' => 'Done: 1 outdated node found', 'update_targets' => ['gateway', 'local', 'beast']]],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'gateway', 'status' => 'done', 'message' => '']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.beast', 'status' => 'done', 'message' => 'Workload node beast updated (4 issues)']],
        ['type' => ProgressEventType::Complete, 'payload' => ['status' => 'succeeded', 'target_version' => '1.2.3']],
    ]));

    [$exitCode, $output] = runCommand($this, 'update:all');

    expect($exitCode)->toBe(0)
        ->and($output)->toMatch('/beast\s+Done \(4 issues\)/')
        ->and($output)->toMatch('/local\s+Done \(2 issues\)/')
        ->and($output)->toContain('Success: All nodes are running on version 1.2.3');
});

it('settles the local fan-out node to Done with the issue count when the local doctor reports drift', function (): void {
    $this->localUpdater->doctorIssues = 2;

    fakeGateway(fakeUpdateAllStartEnvelope());
    app()->instance(GatewayOperationFollower::class, new UpdateAllCommandFakeFollower([
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-updates', 'status' => 'done', 'message' => 'Done: latest version is 1.2.3']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-fleet-versions', 'status' => 'done', 'message' => 'Done: 1 outdated node found', 'update_targets' => ['gateway', 'local', 'beast']]],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.beast', 'status' => 'done', 'message' => 'Workload node beast updated']],
        ['type' => ProgressEventType::Complete, 'payload' => ['status' => 'succeeded', 'target_version' => '1.2.3']],
    ]));

    [$exitCode, $output] = runCommand($this, 'update:all');

    expect($exitCode)->toBe(0)
        // Local runs as a fan-out target after the gateway phase.
        ->and($this->localUpdater->calls)->toBe(['download', 'replace', 'doctor'])
        ->and($output)->toMatch('/local\s+Done \(2 issues\)/');
});

it('skips the local update in json mode on the all-current short-circuit', function (): void {
    fakeGateway(fakeUpdateAllStartEnvelope());
    app()->instance(GatewayOperationFollower::class, new UpdateAllCommandFakeFollower([
        ['type' => ProgressEventType::Complete, 'payload' => [
            'exit_code' => 0,
            'data' => ['status' => 'skipped', 'target_version' => '1.2.3', 'skipped' => true],
        ]],
    ]));

    [$exitCode, $output] = runCommand($this, 'update:all', ['--json' => true]);

    expect($exitCode)->toBe(0)
        // No local download/replace/doctor when the fleet is all-current.
        ->and($this->localUpdater->calls)->toBe([])
        ->and(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toBe([
            'event' => 'complete',
            'data' => ['exit_code' => 0, 'data' => ['status' => 'skipped', 'target_version' => '1.2.3', 'skipped' => true]],
        ]);
});

it('renders the local fan-out node as skipped when the caller is already on the target version', function (): void {
    config()->set('app.version', '1.2.3');

    fakeGateway(fakeUpdateAllStartEnvelope());
    app()->instance(GatewayOperationFollower::class, new UpdateAllCommandFakeFollower([
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-updates', 'status' => 'done', 'message' => 'Done: latest version is 1.2.3']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-fleet-versions', 'status' => 'done', 'message' => 'Done: 1 outdated node found', 'update_targets' => ['gateway', 'local', 'beast']]],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.beast', 'status' => 'done', 'message' => 'Workload node beast updated']],
        ['type' => ProgressEventType::Complete, 'payload' => ['status' => 'succeeded', 'target_version' => '1.2.3']],
    ]));

    [$exitCode, $output] = runCommand($this, 'update:all');

    expect($exitCode)->toBe(0)
        // Local is already current → no download/replace/doctor; row settles skipped.
        ->and($this->localUpdater->calls)->toBe([])
        ->and($output)->toMatch('/local\s+Skipped: already up to date/');
});

it('skips the local download in json mode when the caller is already on the target version', function (): void {
    config()->set('app.version', '1.2.3');

    fakeGateway(fakeUpdateAllStartEnvelope());
    app()->instance(GatewayOperationFollower::class, new UpdateAllCommandFakeFollower([
        ['type' => ProgressEventType::Complete, 'payload' => ['exit_code' => 0, 'data' => ['updates' => []], 'target_version' => '1.2.3']],
    ]));

    [$exitCode] = runCommand($this, 'update:all', ['--json' => true]);

    expect($exitCode)->toBe(0)
        ->and($this->localUpdater->calls)->toBe([]);
});

it('returns failure exit code and json output for terminal operation errors', function (): void {
    fakeGateway(fakeUpdateAllStartEnvelope());
    app()->instance(GatewayOperationFollower::class, new UpdateAllCommandFakeFollower([
        ['type' => ProgressEventType::Error, 'payload' => ['message' => 'Gateway health failed']],
    ]));

    [$exitCode, $output] = runCommand($this, 'update:all', [
        '--json' => true,
    ]);

    $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($decoded)->toBe([
            'event' => 'error',
            'data' => ['message' => 'Gateway health failed'],
        ]);
});

it('surfaces gateway start failures after the local update preflight succeeds', function (): void {
    fakeGateway(fakeErrorEnvelope('authorization_failed', 'Missing gateway admin authority.'), 403);

    [$exitCode, $output] = runCommand($this, 'update:all', [
        '--json' => true,
    ]);

    $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://gateway.test/api/update/all/start');

    expect($exitCode)->toBe(1)
        ->and($decoded['error']['code'])->toBe('authorization_failed')
        ->and($decoded['error']['message'])->toBe('Missing gateway admin authority.');
});

it('follows the durable operation through reconnects in the command path', function (): void {
    config()->set('orbit.gateway.url', 'https://gateway.test');
    config()->set('orbit.gateway.timeout', 30);
    config()->set('orbit.gateway.operation_follow_reconnect_sleep_ms', 0);
    app()->forgetInstance(GatewayApiClient::class);
    app()->forgetInstance(GatewayOperationEventStreamClient::class);
    app()->forgetInstance(GatewayOperationFollower::class);

    $lastEventIds = [];

    Http::fake(function (Request $request) use (&$lastEventIds) {
        if ($request->url() === 'https://gateway.test/api/update/all/start') {
            return Http::response(fakeUpdateAllStartEnvelope(), 200);
        }

        if ($request->url() === 'https://gateway.test/api/operations/run-1/events') {
            $lastEventIds[] = $request->header('Last-Event-ID')[0] ?? null;

            if (count($lastEventIds) === 1) {
                return Http::response(
                    "id: 5\n"
                    ."event: step\n"
                    ."data: {\"message\":\"runner started\"}\n\n",
                    200,
                    ['Content-Type' => 'text/event-stream'],
                );
            }

            return Http::response(
                "id: 6\n"
                ."event: complete\n"
                ."data: {\"exit_code\":0}\n\n",
                200,
                ['Content-Type' => 'text/event-stream'],
            );
        }

        return Http::response('not found', 404);
    });

    [$exitCode, $output] = runCommand($this, 'update:all', [
        '--json' => true,
    ]);

    $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($lastEventIds)->toBe([null, '5'])
        ->and($decoded)->toBe([
            'event' => 'complete',
            'data' => ['exit_code' => 0],
        ]);
});

it('returns gateway failure when the start response does not include an events url', function (): void {
    fakeGateway(fakeSuccessEnvelope([
        'operation_run' => ['id' => 'run-1'],
    ]));

    [$exitCode, $output] = runCommand($this, 'update:all', [
        '--json' => true,
    ]);

    $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($decoded['error']['code'])->toBe('gateway_unavailable');
});

/**
 * @return array{row: int, spinner: string}|null
 */
function updateAllPendingPtySpinnerState(string $capture): ?array
{
    return updateAllPtySpinnerState($capture, 'local', 'Replacing cli binary');
}

/**
 * @return array{row: int, spinner: string}|null
 */
function updateAllPtySpinnerState(string $capture, string $label, ?string $status = null): ?array
{
    $screen = new VirtualTerminalScreen;
    $screen->feed($capture);
    $row = $screen->rowsMatching($label, $status)[0] ?? null;

    if ($row === null) {
        return null;
    }

    return [
        'row' => $row['row'],
        'spinner' => $row['spinner'],
    ];
}

/**
 * @return array{
 *     target_row: int|null,
 *     row_identity_stable: bool,
 *     observed_states: array<string, bool>,
 *     captured: bool,
 *     transcript: string,
 *     cadence_state: array{anchor_us: int|null, anchor_spinner: string|null, first_transition_us: int, last_spinner: string|null}
 * }
 */
function newUpdateAllPtyTargetState(): array
{
    return [
        'target_row' => null,
        'row_identity_stable' => true,
        'observed_states' => [],
        'captured' => false,
        'transcript' => '',
        'cadence_state' => [
            'anchor_us' => null,
            'anchor_spinner' => null,
            'first_transition_us' => -1,
            'last_spinner' => null,
        ],
    ];
}

/**
 * @param  array{
 *     target_row: int|null,
 *     row_identity_stable: bool,
 *     observed_states: array<string, bool>,
 *     captured: bool,
 *     transcript: string,
 *     cadence_state: array{anchor_us: int|null, anchor_spinner: string|null, first_transition_us: int, last_spinner: string|null}
 * }  $state
 * @param  array{row: int, spinner: string}|null  $observation
 */
function recordUpdateAllPtyTargetState(array &$state, ?array $observation, string $capture): void
{
    if ($observation === null || $state['captured']) {
        return;
    }

    if ($state['target_row'] !== null && $state['target_row'] !== $observation['row']) {
        $state['row_identity_stable'] = false;
    }

    $state['target_row'] ??= $observation['row'];
    $state['observed_states'][$observation['spinner']] = true;

    if ($state['row_identity_stable']) {
        updateAllLivenessObserveSpinner($state['cadence_state'], $observation['spinner'], updateAllLivenessNowUs());
    }

    if ($state['row_identity_stable']
        && isset($state['observed_states'][VirtualTerminalScreen::SPINNER_CYAN_OPEN])
        && isset($state['observed_states'][VirtualTerminalScreen::SPINNER_CYAN_FILLED])) {
        $state['captured'] = true;
        $state['transcript'] = $capture;
    }
}

function findPseudoTtyScriptBinary(): ?string
{
    foreach (['/usr/bin/script', '/bin/script'] as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function unusedUpdateAllGatewayLivenessPort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

    if (! is_resource($socket)) {
        throw new RuntimeException("Unable to reserve an update:all gateway liveness port: {$error}");
    }

    $name = stream_socket_get_name($socket, false);
    fclose($socket);

    if (! is_string($name) || ! str_contains($name, ':')) {
        throw new RuntimeException('Unable to determine the update:all gateway liveness port.');
    }

    return (int) substr(strrchr($name, ':'), 1);
}

/**
 * @return array{process: resource, pipes: array<int, resource>}
 */
function startUpdateAllGatewayLivenessRouter(int $port, int $startDelayMicroseconds, int $silentDelayMicroseconds): array
{
    $environment = getenv();

    if (! is_array($environment)) {
        $environment = [];
    }

    $process = proc_open(
        [
            PHP_BINARY,
            base_path('tests/Support/update_all_gateway_liveness_router.php'),
            (string) $port,
        ],
        [
            ['pipe', 'r'],
            ['pipe', 'w'],
            ['pipe', 'w'],
        ],
        $pipes,
        base_path(),
        array_merge($environment, [
            'ORBIT_UPDATE_ALL_LIVENESS_START_DELAY_US' => (string) $startDelayMicroseconds,
            'ORBIT_UPDATE_ALL_LIVENESS_SILENT_US' => (string) $silentDelayMicroseconds,
        ]),
    );

    if (! is_resource($process)) {
        throw new RuntimeException('Could not start the update:all gateway liveness router.');
    }

    fclose($pipes[0]);
    waitForUpdateAllGatewayLivenessRouter($port);

    return [
        'process' => $process,
        'pipes' => $pipes,
    ];
}

/**
 * @param  array{process: resource, pipes: array<int, resource>}  $router
 */
function stopUpdateAllGatewayLivenessRouter(array $router): void
{
    if (proc_get_status($router['process'])['running'] ?? false) {
        proc_terminate($router['process']);
    }

    foreach ($router['pipes'] as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }

    proc_close($router['process']);
}

function waitForUpdateAllGatewayLivenessRouter(int $port): void
{
    $deadline = microtime(true) + 5.0;

    while (microtime(true) < $deadline) {
        $connection = @stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $error, 0.1);

        if (is_resource($connection)) {
            fwrite($connection, "GET /ready HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
            fclose($connection);

            return;
        }

        usleep(20_000);
    }

    throw new RuntimeException('Timed out waiting for the update:all gateway liveness router.');
}

/**
 * @param  list<string>  $command
 * @return list<string>
 */
function pseudoTtyWrappedCommand(string $scriptBinary, string $typescriptPath, array $command): array
{
    $shellCommand = implode(' ', array_map(static fn (string $part): string => escapeshellarg($part), $command));

    if (PHP_OS_FAMILY === 'Darwin') {
        // -F is required on macOS so in-place repaints flush into the typescript
        // file while the wrapped command is still running.
        return [$scriptBinary, '-q', '-F', $typescriptPath, '/bin/sh', '-c', $shellCommand];
    }

    return [
        $scriptBinary,
        '-q',
        '-c',
        $shellCommand,
        $typescriptPath,
    ];
}

function writeUpdateAllLivenessCaptureScript(string $cliRoot, int $replaceDelayMicroseconds): string
{
    $captureScript = tempnam(sys_get_temp_dir(), 'orbit-update-all-capture-');

    if ($captureScript === false) {
        throw new RuntimeException('Could not allocate the update:all liveness capture script.');
    }

    $escapedCliRoot = addslashes($cliRoot);
    $delay = max(0, $replaceDelayMicroseconds);

    $source = <<<PHP
<?php

declare(strict_types=1);

use App\Services\GatewayApiClient;
use App\Services\GatewayOperationEventStreamClient;
use App\Services\GatewayOperationFollower;
use App\Services\Updates\RunsLocalUpdate;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Progress\ProgressEventType;
use Orbit\Core\Progress\VirtualTerminalScreen;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\StreamOutput;

define('LARAVEL_START', microtime(true));

require '{$escapedCliRoot}/vendor/autoload.php';

/** @var \LaravelZero\Framework\Application \$app */
\$app = require '{$escapedCliRoot}/bootstrap/app.php';
/** @var Kernel \$kernel */
\$kernel = \$app->make(Kernel::class);
\$kernel->bootstrap();

config()->set('orbit.gateway.url', 'https://gateway.test');
config()->set('orbit.gateway.timeout', 30);
config()->set('app.version', '0.0.0');
\$app->forgetInstance(GatewayApiClient::class);
\$app->forgetInstance(GatewayOperationEventStreamClient::class);
\$app->forgetInstance(GatewayOperationFollower::class);

Http::fake(['https://gateway.test/*' => Http::response(
    JsonEnvelope::success([
        'operation_run' => ['id' => 'run-1', 'type' => 'update:all', 'status' => 'queued'],
        'update_plan' => [
            'target_version' => '1.2.3',
            'gateway_image' => 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:'.str_repeat('a', 64),
            'manifest_source' => 'github-release',
            'manifest_version' => '1.2.3',
        ],
        'events_url' => '/api/operations/run-1/events',
    ]),
    200,
)]);

\$app->instance(GatewayOperationFollower::class, new class extends GatewayOperationFollower
{
    public function __construct() {}

    /** @param  callable(ProgressEventType, array<string, mixed>): void  \$onEvent */
    public function follow(string \$eventsUrl, callable \$onEvent): array
    {
        foreach ([
            ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-updates', 'status' => 'done', 'message' => 'Done: latest version is 1.2.3']],
            ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-fleet-versions', 'status' => 'done', 'message' => 'Done: 1 outdated node found', 'update_targets' => ['gateway', 'local', 'beast']]],
            ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.beast', 'status' => 'done', 'message' => 'Workload node beast updated']],
            ['type' => ProgressEventType::Complete, 'payload' => ['status' => 'succeeded', 'target_version' => '1.2.3']],
        ] as \$event) {
            \$onEvent(\$event['type'], \$event['payload']);
        }

        return ['type' => ProgressEventType::Complete, 'payload' => ['status' => 'succeeded', 'target_version' => '1.2.3']];
    }
});

\$app->instance(RunsLocalUpdate::class, new class({$delay}) implements RunsLocalUpdate
{
    public function __construct(private int \$replaceDelayMicroseconds) {}

    public function pullSource(): array
    {
        return ['successful' => true, 'exit_code' => 0, 'output' => ''];
    }

    public function downloadBinary(): array
    {
        return [
            'successful' => true,
            'exit_code' => 0,
            'output' => '',
            'staged_path' => '/tmp/staged-orbit',
            'version' => '1.2.3',
        ];
    }

    public function replaceBinary(string \$stagedPath, string \$version): array
    {
        if (\$this->replaceDelayMicroseconds > 0) {
            \$deadline = hrtime(true) + (\$this->replaceDelayMicroseconds * 1000);

            while (hrtime(true) < \$deadline) {
                usleep(50_000);
            }
        }

        return ['successful' => true, 'exit_code' => 0, 'output' => '', 'skipped' => false];
    }

    public function runDoctor(): array
    {
        return ['issues' => 0];
    }

    public function installDependencies(): array
    {
        return ['successful' => true, 'exit_code' => 0, 'output' => ''];
    }

    public function runMigrations(): array
    {
        return ['successful' => true, 'exit_code' => 0, 'output' => ''];
    }
});

if (function_exists('stream_set_write_buffer') && defined('STDOUT') && is_resource(STDOUT)) {
    stream_set_write_buffer(STDOUT, 0);
}

\$output = new StreamOutput(STDOUT, Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL, true);

exit(\$kernel->handle(new ArgvInput(['orbit', 'update:all']), \$output));

PHP;

    file_put_contents($captureScript, $source);

    return $captureScript;
}

function writeUpdateAllGatewayLivenessCaptureScript(string $cliRoot, string $gatewayUrl): string
{
    $captureScript = tempnam(sys_get_temp_dir(), 'orbit-update-all-gateway-capture-');

    if ($captureScript === false) {
        throw new RuntimeException('Could not allocate the update:all gateway liveness capture script.');
    }

    $escapedCliRoot = addslashes($cliRoot);
    $escapedGatewayUrl = addslashes($gatewayUrl);

    $source = <<<PHP
<?php

declare(strict_types=1);

use App\Services\GatewayApiClient;
use App\Services\GatewayOperationEventStreamClient;
use App\Services\GatewayOperationFollower;
use App\Services\Updates\RunsLocalUpdate;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\StreamOutput;

define('LARAVEL_START', microtime(true));

require '{$escapedCliRoot}/vendor/autoload.php';

/** @var \LaravelZero\Framework\Application \$app */
\$app = require '{$escapedCliRoot}/bootstrap/app.php';
/** @var Kernel \$kernel */
\$kernel = \$app->make(Kernel::class);
\$kernel->bootstrap();

config()->set('orbit.gateway.url', '{$escapedGatewayUrl}');
config()->set('orbit.gateway.timeout', 10);
config()->set('orbit.gateway.operation_follow_reconnect_sleep_ms', 0);
config()->set('app.version', '0.0.0');
\$app->forgetInstance(GatewayApiClient::class);
\$app->forgetInstance(GatewayOperationEventStreamClient::class);
\$app->forgetInstance(GatewayOperationFollower::class);

\$app->instance(RunsLocalUpdate::class, new class implements RunsLocalUpdate
{
    public function pullSource(): array
    {
        return ['successful' => true, 'exit_code' => 0, 'output' => ''];
    }

    public function downloadBinary(): array
    {
        return [
            'successful' => true,
            'exit_code' => 0,
            'output' => '',
            'staged_path' => '/tmp/staged-orbit',
            'version' => '9.9.9',
        ];
    }

    public function replaceBinary(string \$stagedPath, string \$version): array
    {
        return ['successful' => true, 'exit_code' => 0, 'output' => '', 'skipped' => false];
    }

    public function runDoctor(): array
    {
        return ['issues' => 0];
    }

    public function installDependencies(): array
    {
        return ['successful' => true, 'exit_code' => 0, 'output' => ''];
    }

    public function runMigrations(): array
    {
        return ['successful' => true, 'exit_code' => 0, 'output' => ''];
    }
});

if (function_exists('stream_set_write_buffer') && defined('STDOUT') && is_resource(STDOUT)) {
    stream_set_write_buffer(STDOUT, 0);
}

\$output = new StreamOutput(STDOUT, Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL, true);

exit(\$kernel->handle(new ArgvInput(['orbit', 'update:all']), \$output));

PHP;

    file_put_contents($captureScript, $source);

    return $captureScript;
}

function stripDecoratedLines(string $output): string
{
    $lines = explode("\n", preg_replace('/\e\[[0-9;?]*[a-zA-Z]/', '', $output) ?? $output);

    return implode("\n", array_map(
        static fn (string $line): string => preg_replace('/^  [○◉●]  /', '', $line) ?? $line,
        $lines,
    ));
}

/**
 * @param  list<string>  $lines
 */
function findStrippedProgressLine(array $lines, string $needle, ?string $statusNeedle = null): ?string
{
    $found = null;

    foreach ($lines as $line) {
        if (! str_contains($line, $needle)) {
            continue;
        }

        if ($statusNeedle !== null && ! str_contains($line, $statusNeedle)) {
            continue;
        }

        $found = $line;
    }

    return $found;
}

/**
 * @return array<string, mixed>
 */
function fakeUpdateAllStartEnvelope(): array
{
    return fakeSuccessEnvelope([
        'operation_run' => [
            'id' => 'run-1',
            'type' => 'update:all',
            'status' => 'queued',
        ],
        'update_plan' => [
            'target_version' => '1.2.3',
            'gateway_image' => 'ghcr.io/hardimpactdev/orbit-gateway:1.2.3@sha256:'.str_repeat('a', 64),
            'manifest_source' => 'github-release',
            'manifest_version' => '1.2.3',
        ],
        'events_url' => '/api/operations/run-1/events',
    ]);
}

class UpdateAllCommandFakeFollower extends GatewayOperationFollower
{
    /**
     * @var list<string>
     */
    public array $eventsUrls = [];

    /**
     * @param  list<array{type: ProgressEventType, payload: array<string, mixed>}>  $events
     */
    public function __construct(
        private array $events,
    ) {}

    /**
     * @param  callable(ProgressEventType, array<string, mixed>): void  $onEvent
     * @return array{type: ProgressEventType, payload: array<string, mixed>}
     */
    #[Override]
    public function follow(string $eventsUrl, callable $onEvent): array
    {
        $this->eventsUrls[] = $eventsUrl;
        $terminal = null;

        foreach ($this->events as $event) {
            $onEvent($event['type'], $event['payload']);

            if ($event['type'] === ProgressEventType::Complete || $event['type'] === ProgressEventType::Error) {
                $terminal = $event;

                break;
            }
        }

        if ($terminal === null) {
            throw new RuntimeException('Fake follower did not receive a terminal event.');
        }

        return $terminal;
    }
}

final class UpdateAllCommandFakeUpdater implements RunsLocalUpdate
{
    /**
     * @var list<string>
     */
    public array $calls = [];

    public ?int $doctorIssues = 0;

    public int $replaceDelayMicroseconds = 0;

    /**
     * @var array<string, mixed>
     */
    public array $results = [
        'pull_source' => ['successful' => true, 'exit_code' => 0, 'output' => ''],
        'download' => ['successful' => true, 'exit_code' => 0, 'output' => '', 'staged_path' => '/tmp/staged-orbit', 'version' => '1.2.3'],
        'install_dependencies' => ['successful' => true, 'exit_code' => 0, 'output' => ''],
        'run_migrations' => ['successful' => true, 'exit_code' => 0, 'output' => ''],
    ];

    /**
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function pullSource(): array
    {
        $this->calls[] = 'pull_source';

        return $this->results['pull_source'];
    }

    /**
     * @return array{successful: bool, exit_code: int, output: string, staged_path: string|null, version: string|null}
     */
    public function downloadBinary(): array
    {
        $this->calls[] = 'download';

        /** @var array{successful: bool, exit_code: int, output: string, staged_path: string|null, version: string|null} */
        return $this->results['download'];
    }

    /**
     * @return array{successful: bool, exit_code: int, output: string, skipped: bool}
     */
    public function replaceBinary(string $stagedPath, string $version): array
    {
        $this->calls[] = 'replace';

        if ($this->replaceDelayMicroseconds > 0) {
            $deadline = hrtime(true) + ($this->replaceDelayMicroseconds * 1000);

            while (hrtime(true) < $deadline) {
                usleep(50_000);
            }
        }

        return ['successful' => true, 'exit_code' => 0, 'output' => '', 'skipped' => false];
    }

    /**
     * @return array{issues: int|null}
     */
    public function runDoctor(): array
    {
        $this->calls[] = 'doctor';

        return ['issues' => $this->doctorIssues];
    }

    /**
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function installDependencies(): array
    {
        $this->calls[] = 'install_dependencies';

        return $this->results['install_dependencies'];
    }

    /**
     * @return array{successful: bool, exit_code: int, output: string}
     */
    public function runMigrations(): array
    {
        $this->calls[] = 'run_migrations';

        return $this->results['run_migrations'];
    }
}

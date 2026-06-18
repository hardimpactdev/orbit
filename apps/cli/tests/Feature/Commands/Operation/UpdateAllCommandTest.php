<?php

declare(strict_types=1);

use App\Services\GatewayApiClient;
use App\Services\GatewayOperationEventStreamClient;
use App\Services\GatewayOperationFollower;
use App\Services\Updates\RunsLocalUpdate;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orbit\Core\Progress\ProgressEventType;

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

it('renders update-all target progress in human mode', function (): void {
    fakeGateway(fakeUpdateAllStartEnvelope());
    app()->instance(GatewayOperationFollower::class, new UpdateAllCommandFakeFollower([
        ['type' => ProgressEventType::Tree, 'payload' => ['title' => 'Update all', 'steps' => [['label' => 'Update gateway']]]],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-updates', 'status' => 'running', 'message' => 'Checking']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-updates', 'status' => 'done', 'message' => 'Done: latest version is 1.2.3']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-fleet-versions', 'status' => 'running', 'message' => 'Checking']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-fleet-versions', 'status' => 'done', 'message' => 'Done: 2 outdated nodes found']],
        ['type' => ProgressEventType::Step, 'payload' => ['message' => 'Fleet update lease acquired']],
        ['type' => ProgressEventType::Step, 'payload' => ['message' => 'Updating orbit-gateway service']],
        ['type' => ProgressEventType::Step, 'payload' => ['message' => 'Gateway services updated']],
        // Gateway sub-steps
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
        ->and($output)->toContain('Updating Orbit')
        ->and($output)->not->toContain('Updating Orbit nodes')
        // No preamble local row before gateway
        ->and($output)->not->toMatch('/local\s+Updating CLI/')
        ->and($output)->toMatch('/Checking for updates\s+Done: latest version is 1.2.3/')
        ->and($output)->toMatch('/Checking fleet versions\s+Done: 2 outdated nodes found/')
        ->and($output)->toMatch('/gateway\s+Updating gateway service/')
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
        ->and($output)->toContain('Updating Orbit')
        ->and($output)->not->toContain('Updating Orbit nodes')
        ->and($output)->toMatch('/Checking for updates\s+Done: latest version is 1.2.3/')
        ->and($output)->toMatch('/Checking fleet versions\s+Done: all nodes running on 1.2.3/')
        // No gateway/local/workload rows appear
        ->and($output)->not->toMatch('/gateway\s+/')
        ->and($output)->not->toMatch('/local\s+/')
        ->and($output)->toContain('Skipped: 1.2.3 is already installed on all nodes');
});

it('renders per-node sub-stages for workload nodes', function (): void {
    fakeGateway(fakeUpdateAllStartEnvelope());
    app()->instance(GatewayOperationFollower::class, new UpdateAllCommandFakeFollower([
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-updates', 'status' => 'done', 'message' => 'Done: latest version is 1.2.3']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-fleet-versions', 'status' => 'done', 'message' => 'Done: 1 outdated node found']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.beast', 'status' => 'running', 'message' => 'Downloading 1.2.3']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.beast', 'status' => 'running', 'message' => 'Replacing cli binary']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.beast', 'status' => 'running', 'message' => 'Running doctor']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'workload.beast', 'status' => 'done', 'message' => 'Workload node beast updated']],
        ['type' => ProgressEventType::Complete, 'payload' => ['status' => 'succeeded', 'target_version' => '1.2.3']],
    ]));

    [$exitCode, $output] = runCommand($this, 'update:all');

    expect($exitCode)->toBe(0)
        ->and($output)->toMatch('/beast\s+Downloading 1\.2\.3/')
        ->and($output)->toMatch('/beast\s+Replacing cli binary/')
        ->and($output)->toMatch('/beast\s+Running doctor/')
        ->and($output)->toMatch('/beast\s+Done/');
});

it('settles the local fan-out node to Done with the issue count when the local doctor reports drift', function (): void {
    $this->localUpdater->doctorIssues = 2;

    fakeGateway(fakeUpdateAllStartEnvelope());
    app()->instance(GatewayOperationFollower::class, new UpdateAllCommandFakeFollower([
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-updates', 'status' => 'done', 'message' => 'Done: latest version is 1.2.3']],
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-fleet-versions', 'status' => 'done', 'message' => 'Done: 1 outdated node found']],
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
        ['type' => ProgressEventType::Step, 'payload' => ['key' => 'check-fleet-versions', 'status' => 'done', 'message' => 'Done: 1 outdated node found']],
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

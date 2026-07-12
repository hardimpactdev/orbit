<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Exceptions\RemoteShellFailed;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Platform\PlatformDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(PlatformDetector::class, new class extends PlatformDetector {
        public function detectLocal(): string
        {
            return 'linux';
        }
    });
});

const DOCTOR_RUN_STREAM_CALLER_WG_IP = '10.6.0.194';

function createDoctorRunStreamCallerNode(array $overrides = []): Node
{
    return createTestGatewayNode([
        'name' => 'doctor-stream-caller',
        'host' => DOCTOR_RUN_STREAM_CALLER_WG_IP,
        'wireguard_address' => DOCTOR_RUN_STREAM_CALLER_WG_IP,
        'platform' => 'linux',
        ...$overrides,
    ]);
}

it('streams doctor verify progress from the gateway', function (): void {
    createDoctorRunStreamCallerNode();

    $response = $this->call(
        'POST',
        '/api/doctor/run',
        [
            'families' => ['node'],
            'mode' => 'verify',
            'self' => true,
        ],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'text/event-stream',
            'REMOTE_ADDR' => DOCTOR_RUN_STREAM_CALLER_WG_IP,
        ],
    );

    $response->assertOk();

    $content = $response->streamedContent();

    expect($content)
        ->toContain('event: tree')
        ->and($content)
        ->toContain('Running Doctor')
        ->and($content)
        ->toContain('Checking node')
        ->and($content)
        ->toContain('event: complete');
});

it('streams doctor panel snapshots before and during node-scoped probes', function (): void {
    createDoctorRunStreamCallerNode();

    $response = $this->call(
        'POST',
        '/api/doctor/run',
        [
            'families' => ['node'],
            'mode' => 'verify',
            'self' => true,
        ],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'text/event-stream',
            'REMOTE_ADDR' => DOCTOR_RUN_STREAM_CALLER_WG_IP,
        ],
    );

    $response->assertOk();

    $frames = doctorRunStreamFrames($response->streamedContent());
    $initial = doctorRunStreamDoctorFrames($frames)[0] ?? null;
    $nodeDone =
        array_values(array_filter(
            doctorRunStreamDoctorFrames($frames),
            static fn (array $frame): bool => (
                ($frame['key'] ?? null) === 'node'
                && ($frame['status'] ?? null) === 'done'
            ),
        ))[0] ?? null;

    expect($initial)
        ->not->toBeNull()->and($initial['doctor']['scope']['families'])->toBe(['node'])->and(
            $initial['doctor']['progress']['state'],
        )->toBe('running')->and($initial['doctor']['progress']['families'][0]['family'])->toBe('node')->and(
            $initial['doctor']['progress']['families'][0]['status'],
        )->toBe('queued')->and($nodeDone)
        ->not->toBeNull()->and($nodeDone['doctor']['issues'])->toBeArray()->and(
            $nodeDone['doctor']['summary'],
        )->toBeArray();
});

it('streams partial fleet doctor snapshots with completed-node issues on node done', function (): void {
    createDoctorRunStreamCallerNode(['name' => 'doctor-stream-caller']);
    createTestAppHostNode(['name' => 'app-dev-1', 'status' => 'active']);
    createTestAppHostNode(['name' => 'app-prod-1', 'status' => 'active'], 'app-prod');

    app()->instance(RemoteShell::class, new FleetDoctorRemoteShell(failingNodeName: 'app-dev-1'));

    $response = $this->call(
        'POST',
        '/api/doctor/run',
        [
            'families' => ['proxy'],
            'mode' => 'verify',
            'all' => true,
        ],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'text/event-stream',
            'REMOTE_ADDR' => DOCTOR_RUN_STREAM_CALLER_WG_IP,
        ],
    );

    $response->assertOk();

    $content = $response->streamedContent();
    $doctorFrames = doctorRunStreamDoctorFrames(doctorRunStreamFrames($content));
    $stepEvents = doctorRunStreamStepEvents($content);
    $appDevDone =
        array_values(array_filter(
            $doctorFrames,
            static fn (array $frame): bool => (
                ($frame['key'] ?? null) === 'app-dev-1'
                && ($frame['status'] ?? null) === 'done'
            ),
        ))[0] ?? null;

    expect($appDevDone)
        ->not
        ->toBeNull()
        ->and($appDevDone['doctor']['scope']['role'])
        ->toBe('fleet')
        ->and($appDevDone['doctor']['progress']['state'])
        ->toBe('running')
        ->and(collect($appDevDone['doctor']['issues'])->pluck('node')->all())
        ->toContain('app-dev-1')
        ->and(doctorRunStreamStepEventIndex($stepEvents, 'app-dev-1', 'running'))
        ->toBeLessThan(doctorRunStreamStepEventIndex($stepEvents, 'app-dev-1', 'done'))
        ->and(doctorRunStreamStepEventIndex($stepEvents, 'app-prod-1', 'running'))
        ->toBeLessThan(doctorRunStreamStepEventIndex($stepEvents, 'app-prod-1', 'done'));
});

it('streams fleet per-node completed and total progress while a node is running', function (): void {
    createDoctorRunStreamCallerNode();
    $appNode = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $appNode->id,
        'path' => '/home/orbit/apps/docs',
    ]);
    Workspace::factory()->create([
        'app_id' => $app->id,
        'name' => 'feature',
        'path' => '/home/orbit/apps/docs/.worktrees/feature',
    ]);
    Workspace::factory()->create([
        'app_id' => $app->id,
        'name' => 'hotfix',
        'path' => '/home/orbit/apps/docs/.worktrees/hotfix',
    ]);
    app()->instance(
        RemoteShell::class,
        new DoctorRunStreamRemoteShell([
            "feature\t0\t1\t0\t0\t1\t1\t0\t0\t0\t\n",
            "hotfix\t0\t1\t0\t0\t1\t1\t0\t0\t0\t\n",
        ]),
    );

    $response = $this->call(
        'POST',
        '/api/doctor/run',
        [
            'mode' => 'verify',
            'families' => ['workspace'],
            'all' => true,
        ],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'text/event-stream',
            'REMOTE_ADDR' => DOCTOR_RUN_STREAM_CALLER_WG_IP,
        ],
    );

    $response->assertOk();

    $nodeProgress = doctorRunStreamFleetNodeCheckProgressSnapshots(
        doctorRunStreamDoctorFrames(doctorRunStreamFrames($response->streamedContent())),
        'app-1',
    );

    expect($nodeProgress)
        ->not
        ->toBeEmpty()
        ->and($nodeProgress)
        ->toContain([
            'node' => 'app-1',
            'status' => 'running',
            'completed' => 0,
            'total' => 2,
        ])
        ->and($nodeProgress)
        ->toContain([
            'node' => 'app-1',
            'status' => 'running',
            'completed' => 1,
            'total' => 2,
        ])
        ->and(collect($nodeProgress)->contains(
            static fn (array $snapshot): bool => in_array($snapshot['status'] ?? null, ['running', 'checking'], true)
            && ($snapshot['completed'] ?? null) === ($snapshot['total'] ?? null),
        ))
        ->toBeFalse()
        ->and(collect($nodeProgress)->pluck('completed')->unique()->count())
        ->toBeGreaterThan(1);
});

it('streams fleet doctor progress per node only with explicit all scope', function (): void {
    createDoctorRunStreamCallerNode(['name' => 'doctor-stream-caller']);
    createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);

    $response = $this->call(
        'POST',
        '/api/doctor/run',
        [
            'families' => ['node'],
            'mode' => 'verify',
            'all' => true,
        ],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'text/event-stream',
            'REMOTE_ADDR' => DOCTOR_RUN_STREAM_CALLER_WG_IP,
        ],
    );

    $response->assertOk();

    $stepEvents = doctorRunStreamStepEvents($response->streamedContent());

    expect($stepEvents)
        ->not
        ->toBeEmpty()
        ->and(collect($stepEvents)->contains(
            static fn (array $event): bool => $event['key'] === 'app-1' && $event['status'] === 'running',
        ))
        ->toBeTrue()
        ->and(collect($stepEvents)->contains(
            static fn (array $event): bool => $event['key'] === 'app-1' && $event['status'] === 'done',
        ))
        ->toBeTrue();
});

it('streams fleet doctor terminal error when a node proxy probe raises RemoteShellFailed', function (): void {
    createDoctorRunStreamCallerNode(['name' => 'doctor-stream-caller']);
    createTestAppHostNode(['name' => 'app-dev-1', 'status' => 'active']);
    createTestAppHostNode(['name' => 'app-prod-1', 'status' => 'active'], 'app-prod');

    app()->instance(RemoteShell::class, new FleetDoctorRemoteShell(failingNodeName: 'app-prod-1'));

    $response = $this->call(
        'POST',
        '/api/doctor/run',
        [
            'families' => ['proxy'],
            'mode' => 'verify',
            'all' => true,
        ],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'text/event-stream',
            'REMOTE_ADDR' => DOCTOR_RUN_STREAM_CALLER_WG_IP,
        ],
    );

    $response->assertOk();

    $content = $response->streamedContent();
    $stepEvents = doctorRunStreamStepEvents($content);
    $frames = doctorRunStreamFrames($content);
    $terminalFrame = collect($frames)->last();
    $doctor = $terminalFrame['data']['data']['data']['doctor'] ?? null;
    $issueKeys = collect(is_array($doctor) ? $doctor['issues'] ?? [] : [])->pluck('key')->all();

    expect($stepEvents)
        ->toContain(['key' => 'app-dev-1', 'status' => 'done'])
        ->and($stepEvents)
        ->toContain(['key' => 'app-prod-1', 'status' => 'done'])
        ->and($terminalFrame['event'] ?? null)
        ->toBe('error')
        ->and($terminalFrame['data']['data']['code'] ?? null)
        ->toBe('drift_detected')
        ->and($doctor)
        ->toBeArray()
        ->and($doctor['nodes'] ?? null)
        ->toBeArray()
        ->and($doctor['issues'] ?? null)
        ->not
        ->toBeEmpty()
        ->and($issueKeys)
        ->toContain('proxy.node_probe_failed');
});

it('streams omitted scope as caller-node family progress instead of fleet progress', function (): void {
    createDoctorRunStreamCallerNode(['name' => 'doctor-stream-caller']);
    createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);

    $response = $this->call(
        'POST',
        '/api/doctor/run',
        [
            'families' => ['node'],
            'mode' => 'verify',
        ],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'text/event-stream',
            'REMOTE_ADDR' => DOCTOR_RUN_STREAM_CALLER_WG_IP,
        ],
    );

    $response->assertOk();

    $frames = doctorRunStreamFrames($response->streamedContent());
    $doctorFrames = doctorRunStreamDoctorFrames($frames);
    $stepEvents = doctorRunStreamStepEvents($response->streamedContent());

    expect($doctorFrames)
        ->not
        ->toBeEmpty()
        ->and($doctorFrames[0]['doctor']['scope']['node'])
        ->toBe('doctor-stream-caller')
        ->and(doctorRunStreamStepEventIndex($stepEvents, 'node', 'running'))
        ->toBeLessThan(doctorRunStreamStepEventIndex($stepEvents, 'node', 'done'));
});

it('streams node family completed and total for opaque composite checks', function (): void {
    createDoctorRunStreamCallerNode();

    $response = $this->call(
        'POST',
        '/api/doctor/run',
        [
            'families' => ['node'],
            'mode' => 'verify',
            'self' => true,
        ],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'text/event-stream',
            'REMOTE_ADDR' => DOCTOR_RUN_STREAM_CALLER_WG_IP,
        ],
    );

    $response->assertOk();

    $nodeProgress = doctorRunStreamFamilyCheckProgressSnapshots(
        doctorRunStreamDoctorFrames(doctorRunStreamFrames($response->streamedContent())),
        'node',
    );

    expect($nodeProgress)->toContain([
        'family' => 'node',
        'status' => 'checking',
        'completed' => 0,
        'total' => 1,
    ])->and(collect($nodeProgress)->contains(
        static fn (array $snapshot): bool => (
            ($snapshot['status'] ?? null) === 'checking'
            && ($snapshot['completed'] ?? null) === ($snapshot['total'] ?? null)
        ),
    ))->toBeFalse();
});

it('streams app family totals that include app and runtime-config scans', function (): void {
    createDoctorRunStreamCallerNode();
    $appNode = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
    App::factory()->create([
        'name' => 'docs',
        'node_id' => $appNode->id,
        'path' => '/home/orbit/apps/docs',
        'document_root' => 'public',
    ]);
    app()->instance(
        RemoteShell::class,
        new DoctorRunStreamRemoteShell([
            "docs\t0\t0\t1\t1\t0\t0\t0\t0\t0\t0\t0\t0\t0\n",
        ]),
    );

    $response = $this->call(
        'POST',
        '/api/doctor/run',
        [
            'mode' => 'verify',
            'families' => ['app'],
            'node' => 'app-1',
        ],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'text/event-stream',
            'REMOTE_ADDR' => DOCTOR_RUN_STREAM_CALLER_WG_IP,
        ],
    );

    $response->assertOk();

    $appProgress = doctorRunStreamFamilyCheckProgressSnapshots(
        doctorRunStreamDoctorFrames(doctorRunStreamFrames($response->streamedContent())),
        'app',
    );

    expect($appProgress)
        ->not->toBeEmpty()->and(collect($appProgress)->pluck('total')->unique()->all())->toBe([2])->and(
            $appProgress,
        )->toContain([
            'family' => 'app',
            'status' => 'checking',
            'completed' => 1,
            'total' => 2,
        ])->and($appProgress)
        ->not->toContain([
            'family' => 'app',
            'status' => 'checking',
            'completed' => 1,
            'total' => 1,
        ]);
});

it('streams per-family completed and total check counts when workspace inventory is knowable', function (): void {
    createDoctorRunStreamCallerNode();
    $appNode = createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);
    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $appNode->id,
        'path' => '/home/orbit/apps/docs',
    ]);
    Workspace::factory()->create([
        'app_id' => $app->id,
        'name' => 'feature',
        'path' => '/home/orbit/apps/docs/.worktrees/feature',
    ]);
    Workspace::factory()->create([
        'app_id' => $app->id,
        'name' => 'hotfix',
        'path' => '/home/orbit/apps/docs/.worktrees/hotfix',
    ]);
    app()->instance(
        RemoteShell::class,
        new DoctorRunStreamRemoteShell([
            "feature\t0\t1\t0\t0\t1\t1\t0\t0\t0\t\n",
            "hotfix\t0\t1\t0\t0\t1\t1\t0\t0\t0\t\n",
        ]),
    );

    $response = $this->call(
        'POST',
        '/api/doctor/run',
        [
            'mode' => 'verify',
            'families' => ['workspace'],
            'node' => 'app-1',
        ],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'text/event-stream',
            'REMOTE_ADDR' => DOCTOR_RUN_STREAM_CALLER_WG_IP,
        ],
    );

    $response->assertOk();

    $workspaceProgress = doctorRunStreamFamilyCheckProgressSnapshots(
        doctorRunStreamDoctorFrames(doctorRunStreamFrames($response->streamedContent())),
        'workspace',
    );

    expect($workspaceProgress)
        ->not
        ->toBeEmpty()
        ->and($workspaceProgress)
        ->toContain([
            'family' => 'workspace',
            'status' => 'checking',
            'completed' => 0,
            'total' => 2,
        ])
        ->and($workspaceProgress)
        ->toContain([
            'family' => 'workspace',
            'status' => 'checking',
            'completed' => 1,
            'total' => 2,
        ])
        ->and(collect($workspaceProgress)->contains(
            static fn (array $snapshot): bool => (
                ($snapshot['status'] ?? null) === 'checking'
                && ($snapshot['completed'] ?? null) === ($snapshot['total'] ?? null)
            ),
        ))
        ->toBeFalse();
});

it('streams node-scoped doctor progress per family as each family is probed', function (): void {
    createDoctorRunStreamCallerNode();
    createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);

    $response = $this->call(
        'POST',
        '/api/doctor/run',
        [
            'mode' => 'verify',
            'node' => 'app-1',
        ],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'text/event-stream',
            'REMOTE_ADDR' => DOCTOR_RUN_STREAM_CALLER_WG_IP,
        ],
    );

    $response->assertOk();

    $stepEvents = doctorRunStreamStepEvents($response->streamedContent());

    expect(doctorRunStreamStepEventIndex($stepEvents, 'node', 'running'))
        ->toBeLessThan(doctorRunStreamStepEventIndex($stepEvents, 'node', 'done'))
        ->and(doctorRunStreamStepEventIndex($stepEvents, 'node', 'done'))
        ->toBeLessThan(doctorRunStreamStepEventIndex($stepEvents, 'app', 'running'));
});

/**
 * @return list<array{key: string, status: string}>
 */
function doctorRunStreamStepEvents(string $content): array
{
    $events = [];

    foreach (preg_split("/\r\n\r\n|\n\n/", trim($content)) ?: [] as $frame) {
        if (! str_contains($frame, "event: step\n")) {
            continue;
        }

        $dataLine =
            array_values(array_filter(
                explode("\n", $frame),
                static fn (string $line): bool => str_starts_with($line, 'data: '),
            ))[0] ?? null;

        if ($dataLine === null) {
            continue;
        }

        /** @var array{key?: string, status?: string} $payload */
        $payload = json_decode(substr($dataLine, 6), true, flags: JSON_THROW_ON_ERROR);

        if (! is_string($payload['key'] ?? null) || ! is_string($payload['status'] ?? null)) {
            continue;
        }

        $events[] = [
            'key' => $payload['key'],
            'status' => $payload['status'],
        ];
    }

    return $events;
}

/**
 * @return list<array{event: string, data: array<string, mixed>}>
 */
function doctorRunStreamFrames(string $content): array
{
    $frames = [];

    foreach (preg_split("/\r\n\r\n|\n\n/", trim($content)) ?: [] as $frame) {
        $event = null;
        $data = null;

        foreach (explode("\n", $frame) as $line) {
            if (str_starts_with($line, 'event: ')) {
                $event = substr($line, 7);
            }

            if (str_starts_with($line, 'data: ')) {
                $data = json_decode(substr($line, 6), true, flags: JSON_THROW_ON_ERROR);
            }
        }

        if (is_string($event) && is_array($data)) {
            $frames[] = [
                'event' => $event,
                'data' => $data,
            ];
        }
    }

    return $frames;
}

/**
 * @param  list<array{event: string, data: array<string, mixed>}>  $frames
 * @return list<array<string, mixed>>
 */
function doctorRunStreamDoctorFrames(array $frames): array
{
    $doctorFrames = [];

    foreach ($frames as $frame) {
        if ($frame['event'] !== 'step') {
            continue;
        }

        $data = $frame['data'];

        if (! is_array($data['doctor'] ?? null)) {
            continue;
        }

        $doctorFrames[] = $data;
    }

    return $doctorFrames;
}

/**
 * @param  list<array{key: string, status: string}>  $events
 */
function doctorRunStreamStepEventIndex(array $events, string $key, string $status): int
{
    foreach ($events as $index => $event) {
        if ($event['key'] === $key && $event['status'] === $status) {
            return $index;
        }
    }

    throw new RuntimeException("Missing step event {$key} {$status}.");
}

/**
 * @param  array<string, mixed>  $frame
 * @return list<array{node: string, status: string, completed: int, total: int}>
 */
function doctorRunStreamFleetNodeProgressSnapshotsFromFrame(array $frame, string $nodeName): array
{
    $doctor = $frame['doctor'] ?? null;

    if (! is_array($doctor)) {
        return [];
    }

    $nodes = $doctor['progress']['nodes'] ?? null;

    if (! is_array($nodes)) {
        return [];
    }

    $snapshots = [];

    foreach ($nodes as $entry) {
        if (! is_array($entry)) {
            continue;
        }

        if (($entry['node'] ?? null) !== $nodeName) {
            continue;
        }

        if (! is_int($entry['completed'] ?? null) || ! is_int($entry['total'] ?? null)) {
            continue;
        }

        $snapshots[] = [
            'node' => $nodeName,
            'status' => is_string($entry['status'] ?? null) ? $entry['status'] : '',
            'completed' => $entry['completed'],
            'total' => $entry['total'],
        ];
    }

    return $snapshots;
}

/**
 * @param  list<array<string, mixed>>  $doctorFrames
 * @return list<array{node: string, status: string, completed: int, total: int}>
 */
function doctorRunStreamFleetNodeCheckProgressSnapshots(array $doctorFrames, string $nodeName): array
{
    $snapshots = [];

    foreach ($doctorFrames as $frame) {
        if (($frame['key'] ?? null) !== $nodeName || ($frame['status'] ?? null) !== 'running') {
            continue;
        }

        $snapshots = [
            ...$snapshots,
            ...doctorRunStreamFleetNodeProgressSnapshotsFromFrame($frame, $nodeName),
        ];
    }

    return $snapshots;
}

function doctorRunStreamFamilyCheckProgressSnapshots(array $doctorFrames, string $family): array
{
    $snapshots = [];

    foreach ($doctorFrames as $frame) {
        $doctor = $frame['doctor'] ?? null;

        if (! is_array($doctor)) {
            continue;
        }

        $families = $doctor['progress']['families'] ?? null;

        if (! is_array($families)) {
            continue;
        }

        foreach ($families as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (($entry['family'] ?? null) !== $family) {
                continue;
            }

            if (! is_int($entry['completed'] ?? null) || ! is_int($entry['total'] ?? null)) {
                continue;
            }

            $snapshots[] = [
                'family' => $family,
                'status' => is_string($entry['status'] ?? null) ? $entry['status'] : '',
                'completed' => $entry['completed'],
                'total' => $entry['total'],
            ];
        }
    }

    return $snapshots;
}

final class FleetDoctorRemoteShell implements RemoteShell
{
    public function __construct(
        private readonly string $failingNodeName = 'app-prod-1',
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        if (
            $node->name === $this->failingNodeName
            && str_contains($script, '/etc/caddy/sites/*.caddy')
            && ($options['throw'] ?? false) === true
        ) {
            $result = new RemoteShellResult(exitCode: 1, stdout: '', stderr: '', durationMs: 1);

            throw new RemoteShellFailed($node, $script, $result);
        }

        if (str_contains($script, 'docker container ls')) {
            return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
        }

        if (str_contains($script, 'orbit-proxy-doctor:caddy-container-probe')) {
            return new RemoteShellResult(exitCode: 0, stdout: "available\ttrue\ttrue\n", stderr: '', durationMs: 1);
        }

        return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

final class DoctorRunStreamRemoteShell implements RemoteShell
{
    /** @var list<string> */
    private array $perRouteStdouts;

    /**
     * @param  list<string>  $perRouteStdouts
     */
    public function __construct(array $perRouteStdouts)
    {
        $this->perRouteStdouts = $perRouteStdouts;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        if (str_contains($script, 'docker container ls')) {
            return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
        }

        if (str_contains($script, "dir='/home/orbit/.config/orbit/apps'")) {
            return new RemoteShellResult(exitCode: 0, stdout: "orbit-config-dir:absent\n", stderr: '', durationMs: 1);
        }

        return new RemoteShellResult(
            exitCode: 0,
            stdout: array_shift($this->perRouteStdouts) ?? '',
            stderr: '',
            durationMs: 1,
        );
    }
}

<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Platform\PlatformDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(PlatformDetector::class, new class extends PlatformDetector
    {
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

    $response = $this->call('POST', '/api/doctor/run', [
        'families' => ['node'],
        'mode' => 'verify',
        'self' => true,
    ], [], [], [
        'HTTP_ACCEPT' => 'text/event-stream',
        'REMOTE_ADDR' => DOCTOR_RUN_STREAM_CALLER_WG_IP,
    ]);

    $response->assertOk();

    $content = $response->streamedContent();

    expect($content)->toContain('event: tree')
        ->and($content)->toContain('Running Doctor')
        ->and($content)->toContain('Checking node')
        ->and($content)->toContain('event: complete');
});

it('streams doctor panel snapshots before and during node-scoped probes', function (): void {
    createDoctorRunStreamCallerNode();

    $response = $this->call('POST', '/api/doctor/run', [
        'families' => ['node'],
        'mode' => 'verify',
        'self' => true,
    ], [], [], [
        'HTTP_ACCEPT' => 'text/event-stream',
        'REMOTE_ADDR' => DOCTOR_RUN_STREAM_CALLER_WG_IP,
    ]);

    $response->assertOk();

    $frames = doctorRunStreamFrames($response->streamedContent());
    $initial = doctorRunStreamDoctorFrames($frames)[0] ?? null;
    $nodeDone = array_values(array_filter(
        doctorRunStreamDoctorFrames($frames),
        static fn (array $frame): bool => ($frame['key'] ?? null) === 'node' && ($frame['status'] ?? null) === 'done',
    ))[0] ?? null;

    expect($initial)->not->toBeNull()
        ->and($initial['doctor']['scope']['families'])->toBe(['node'])
        ->and($initial['doctor']['progress']['state'])->toBe('running')
        ->and($initial['doctor']['progress']['families'][0]['family'])->toBe('node')
        ->and($initial['doctor']['progress']['families'][0]['status'])->toBe('queued')
        ->and($nodeDone)->not->toBeNull()
        ->and($nodeDone['doctor']['issues'])->toBeArray()
        ->and($nodeDone['doctor']['summary'])->toBeArray();
});

it('streams fleet doctor progress per node instead of batching all running steps before probing', function (): void {
    createDoctorRunStreamCallerNode(['name' => 'doctor-stream-caller']);
    createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);

    $response = $this->call('POST', '/api/doctor/run', [
        'families' => ['node'],
        'mode' => 'verify',
    ], [], [], [
        'HTTP_ACCEPT' => 'text/event-stream',
        'REMOTE_ADDR' => DOCTOR_RUN_STREAM_CALLER_WG_IP,
    ]);

    $response->assertOk();

    $stepEvents = doctorRunStreamStepEvents($response->streamedContent());

    expect($stepEvents)->not->toBeEmpty()
        ->and(doctorRunStreamStepEventIndex($stepEvents, 'app-1', 'done'))
        ->toBeLessThan(doctorRunStreamStepEventIndex($stepEvents, 'doctor-stream-caller', 'running'));
});

it('streams node-scoped doctor progress per family as each family is probed', function (): void {
    createDoctorRunStreamCallerNode();
    createTestAppHostNode(['name' => 'app-1', 'status' => 'active']);

    $response = $this->call('POST', '/api/doctor/run', [
        'mode' => 'verify',
        'node' => 'app-1',
    ], [], [], [
        'HTTP_ACCEPT' => 'text/event-stream',
        'REMOTE_ADDR' => DOCTOR_RUN_STREAM_CALLER_WG_IP,
    ]);

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

        $dataLine = array_values(array_filter(
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

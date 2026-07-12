<?php

declare(strict_types=1);

use App\Models\Node;
use App\Services\Operations\OperationRunRecorder;
use App\Services\Operations\RemoteNodeDoctor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(
        \App\Services\RemoteShell\RunsInternalCommands::class,
        app(\App\Services\RemoteShell\RemoteLocalExecutor::class),
    );
});

it('runs node doctor self through agent-push internal command', function (): void {
    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'app-dev-1',
            'wireguard_address' => '10.44.0.55',
        ]);
    $run = app(OperationRunRecorder::class)->queued('doctor-self-test', 'gateway', operationType: 'update:all');

    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.55:9477/v1/commands' => remote_node_doctor_agent_response(
            output: implode("\n", [
                json_encode(['event' => 'doctor.node.start'], JSON_THROW_ON_ERROR),
                json_encode([
                    'event' => 'doctor.node.done',
                    'data' => [
                        'doctor' => [
                            'summary' => [
                                'issues' => 2,
                            ],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
            ]),
        ),
    ]);

    $issues = app(RemoteNodeDoctor::class)->issues($node, $run);
    $requests = remote_node_doctor_agent_requests('10.44.0.55');

    expect($issues)
        ->toBe(2)
        ->and($requests)
        ->toHaveCount(1)
        ->and($requests[0]['argv'] ?? [])
        ->toContain('internal:doctor-self')
        ->and($requests[0]['operation_id'] ?? null)
        ->toBe((string) $run->id);
});

it('returns null when agent-push doctor transport fails', function (): void {
    $node = Node::factory()
        ->appDev()
        ->managed()
        ->create([
            'name' => 'app-dev-1',
            'wireguard_address' => '10.44.0.56',
        ]);
    $run = app(OperationRunRecorder::class)->queued('doctor-self-failure-test', 'gateway', operationType: 'update:all');

    Http::preventStrayRequests();
    Http::fake([
        'http://10.44.0.56:9477/v1/commands' => Http::response('unavailable', 503),
    ]);

    expect(app(RemoteNodeDoctor::class)->issues($node, $run))->toBeNull();
});

function remote_node_doctor_agent_response(string $output, int $exitCode = 0): array
{
    return [
        'transport' => 'agent-push',
        'operation_id' => 'doctor-self-test',
        'binary' => 'orbit',
        'status' => $exitCode === 0 ? 'succeeded' : 'failed',
        'exit_code' => $exitCode,
        'frames' => [
            [
                'type' => 'stdout',
                'message' => json_encode([
                    'success' => [
                        'data' => [
                            'exit_code' => 0,
                            'output' => $output,
                            'stderr' => '',
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
            ],
            [
                'type' => 'exit',
                'message' => (string) $exitCode,
            ],
        ],
    ];
}

/**
 * @return list<Request>
 */
function remote_node_doctor_agent_requests(string $wireguardAddress): array
{
    return Http::recorded(
        fn (Request $request): bool => $request->url() === "http://{$wireguardAddress}:9477/v1/commands",
    )
        ->map(fn (array $record): Request => $record[0])
        ->values()
        ->all();
}

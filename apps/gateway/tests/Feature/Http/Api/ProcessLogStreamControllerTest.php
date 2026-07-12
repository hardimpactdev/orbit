<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\LocalGatewaySettings;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\call;

uses(RefreshDatabase::class);

const PROCESS_LOG_STREAM_CALLER_WG_IP = '10.6.0.96';

const PROCESS_LOG_STREAM_TARGET_WG_IP = '10.6.0.97';

describe('ProcessLogStreamStartController', function (): void {
    it('starts operation websocket process log streams with target-side publisher metadata', function (): void {
        config()->set('orbit.operation_token_secret', 'process-log-stream-secret');
        LocalGatewaySettings::current()->fill(['gateway_url' => 'https://gateway.test'])->save();
        Http::preventStrayRequests();
        Http::fake([
            'http://'.PROCESS_LOG_STREAM_TARGET_WG_IP.':9477/v1/commands/stream' => Http::response(
                "target output is published by the node\n",
                200,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            ),
        ]);
        createTestGatewayNode([
            'name' => 'caller',
            'host' => PROCESS_LOG_STREAM_CALLER_WG_IP,
            'wireguard_address' => PROCESS_LOG_STREAM_CALLER_WG_IP,
        ]);
        $appNode = createTestAppHostNode([
            'name' => 'app-1',
            'host' => PROCESS_LOG_STREAM_TARGET_WG_IP,
            'wireguard_address' => PROCESS_LOG_STREAM_TARGET_WG_IP,
            'managed' => true,
            'status' => 'active',
        ]);
        $app = process_log_stream_create_app(['name' => 'docs', 'node_id' => $appNode->id]);
        process_log_stream_create_process($app, name: 'vite');
        $response = process_log_stream_start_api_call('agent-push');
        $operationRunId = $response->json('success.data.operation.uuid');

        $response
            ->assertAccepted()
            ->assertJsonPath(
                'success.data.operation.stream_descriptor_url',
                "/api/operations/{$operationRunId}/stream",
            );

        expect($operationRunId)
            ->toBeString()
            ->not->toBeEmpty();

        app()->terminate();

        $agentPushRequest = process_log_stream_first_agent_push_request();
        $operationStream = process_log_stream_operation_payload($agentPushRequest);

        expect($agentPushRequest)
            ->not->toBeNull()->and($operationStream)->toMatchArray([
                'operation_uuid' => $operationRunId,
                'channel' => "private-operations.{$operationRunId}",
                'gateway_url' => 'https://gateway.test',
                'ca_pem_path' => '/home/orbit/.config/orbit/ca/root.crt',
                'publish_endpoint' => "/api/operations/{$operationRunId}/stream/publish",
                'stop_decision_endpoint' => "/api/operations/{$operationRunId}/stream/stop-decision",
            ])->and($operationStream['publisher_token'] ?? null)->toBeString()
            ->not->toBeEmpty();
    });
});

/**
 * @param  array<string, mixed>  $attributes
 */
function process_log_stream_create_app(array $attributes): App
{
    $app = App::factory()->create($attributes);

    if (! $app instanceof App) {
        throw new RuntimeException('Expected app factory to create an app model.');
    }

    return $app;
}

function process_log_stream_create_process(App $app, string $name): Process
{
    $process = Process::factory()->create([
        'node_id' => $app->node_id,
        'owner_type' => $app->getMorphClass(),
        'owner_id' => $app->getKey(),
        'name' => $name,
    ]);

    if (! $process instanceof Process) {
        throw new RuntimeException('Expected process factory to create a process model.');
    }

    return $process;
}

function process_log_stream_start_api_call(string $transportPreference): TestResponse
{
    return call(
        method: 'POST',
        uri: '/api/processes/vite/log-stream',
        parameters: [
            'app' => 'docs',
            'lines' => 5,
        ],
        cookies: [],
        files: [],
        server: [
            'REMOTE_ADDR' => PROCESS_LOG_STREAM_CALLER_WG_IP,
            'HTTP_X_ORBIT_NODE_TRANSPORT_PREFERENCE' => $transportPreference,
        ],
    );
}

function process_log_stream_first_agent_push_request(): ?Illuminate\Http\Client\Request
{
    foreach (Http::recorded() as $record) {
        $request = $record[0] ?? null;

        if (! $request instanceof Illuminate\Http\Client\Request) {
            continue;
        }

        if ($request->url() === 'http://'.PROCESS_LOG_STREAM_TARGET_WG_IP.':9477/v1/commands/stream') {
            return $request;
        }
    }

    return null;
}

/**
 * @return array<string, mixed>
 */
function process_log_stream_operation_payload(?Illuminate\Http\Client\Request $request): array
{
    if (! $request instanceof Illuminate\Http\Client\Request) {
        return [];
    }

    $input = $request->data()['input'] ?? null;

    if (! is_string($input)) {
        return [];
    }

    $payload = json_decode($input, associative: true);

    if (! is_array($payload)) {
        return [];
    }

    $operationStream = $payload['operation_stream'] ?? null;

    if (! is_array($operationStream)) {
        return [];
    }

    /** @var array<string, mixed> $operationStream */
    return $operationStream;
}

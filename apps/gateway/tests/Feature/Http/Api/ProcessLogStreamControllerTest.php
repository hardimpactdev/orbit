<?php

declare(strict_types=1);

use App\Contracts\RemoteShellStream;
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

const PROCESS_LOG_STREAM_URI = '/api/processes/vite/log?app=docs&lines=5&follow=1';

describe('ProcessLogController follow stream', function (): void {
    it('streams followed process log output through agent-push by default', function (): void {
        Http::preventStrayRequests();
        Http::fake([
            'http://'.PROCESS_LOG_STREAM_TARGET_WG_IP.':9477/v1/commands/stream' => Http::response(
                "streamed vite line\n",
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
            'orbit_agent_capable' => true,
            'status' => 'active',
        ]);
        $app = process_log_stream_create_app(['name' => 'docs', 'node_id' => $appNode->id]);
        process_log_stream_create_process($app, name: 'vite');
        $stream = new ProcessLogApiRecordingRemoteStream;
        app()->instance(RemoteShellStream::class, $stream);

        $response = process_log_stream_api_call('agent-push');

        $response
            ->assertStreamed()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertStreamedContent("streamed vite line\n");

        expect($stream->scripts)->toBeEmpty();

        Http::assertSent(
            fn (Illuminate\Http\Client\Request $request): bool => process_log_stream_agent_push_request_matches(
                $request,
            ),
        );
    });

    it('streams followed process log output only through explicit RemoteShell fallback', function (): void {
        Http::preventStrayRequests();
        createTestGatewayNode([
            'name' => 'caller',
            'host' => PROCESS_LOG_STREAM_CALLER_WG_IP,
            'wireguard_address' => PROCESS_LOG_STREAM_CALLER_WG_IP,
        ]);
        $appNode = createTestAppHostNode(['name' => 'app-1']);
        $app = process_log_stream_create_app(['name' => 'docs', 'node_id' => $appNode->id]);
        process_log_stream_create_process($app, name: 'vite');
        $stream = new ProcessLogApiRecordingRemoteStream;
        app()->instance(RemoteShellStream::class, $stream);

        $response = process_log_stream_api_call('transitional-ssh-fallback');

        $response
            ->assertStreamed()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertStreamedContent("streamed vite line\n");

        expect($stream->scripts)->toBe([
            "sudo journalctl -u 'orbit_docs_main_vite.service' -n 5 -f --no-pager --output=short-iso 2>&1",
        ]);
    });

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
            'orbit_agent_capable' => true,
            'status' => 'active',
        ]);
        $app = process_log_stream_create_app(['name' => 'docs', 'node_id' => $appNode->id]);
        process_log_stream_create_process($app, name: 'vite');
        $stream = new ProcessLogApiRecordingRemoteStream;
        app()->instance(RemoteShellStream::class, $stream);

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
            ->not
            ->toBeEmpty()
            ->and($stream->scripts)
            ->toBeEmpty();

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

function process_log_stream_api_call(string $transportPreference, string $uri = PROCESS_LOG_STREAM_URI): TestResponse
{
    return call(
        'GET',
        $uri,
        [],
        [],
        [],
        [
            'REMOTE_ADDR' => PROCESS_LOG_STREAM_CALLER_WG_IP,
            'HTTPS' => 'on',
            'HTTP_HOST' => 'gateway.test',
            'SERVER_PORT' => '443',
            'HTTP_X_ORBIT_NODE_TRANSPORT_PREFERENCE' => $transportPreference,
        ],
    );
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

function process_log_stream_agent_push_request_matches(Illuminate\Http\Client\Request $request): bool
{
    $body = $request->body();

    return $request->url() === 'http://'.PROCESS_LOG_STREAM_TARGET_WG_IP.':9477/v1/commands/stream'
    && process_log_stream_body_contains_all($body, [
        '"command_id":"orbit.agent.binary"',
        '"binary":"orbit"',
        '"argv":["internal:process-logs"',
        '"stream":true',
        '\\"backend\\":\\"systemd\\"',
        '\\"runtime_unit\\":\\"orbit_docs_main_vite\\"',
        '\\"lines\\":5',
        '\\"follow\\":true',
    ]);
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

/**
 * @param  list<string>  $needles
 */
function process_log_stream_body_contains_all(string $body, array $needles): bool
{
    foreach ($needles as $needle) {
        if (! str_contains($body, $needle)) {
            return false;
        }
    }

    return true;
}

final class ProcessLogApiRecordingRemoteStream implements RemoteShellStream
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  callable(string): void  $onOutput
     * @param  array<string, mixed>  $options
     */
    public function stream(Node $node, string $script, callable $onOutput, array $options = []): int
    {
        $this->scripts[] = $script;
        $onOutput("streamed vite line\n");

        return 0;
    }
}

<?php

declare(strict_types=1);

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\ProcessEventType;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use App\Models\ProcessEvent;
use App\Models\Project;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Processes\ProcessStreamRuntimeConfig;
use App\Services\Processes\ProcessStreamSleeper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;

uses(RefreshDatabase::class);

const PROCESS_STREAM_CALLER_WG_IP = '10.6.0.91';

function createProcessStreamCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => PROCESS_STREAM_CALLER_WG_IP,
        'wireguard_address' => PROCESS_STREAM_CALLER_WG_IP,
    ], $overrides));
}

function grantProcessStreamAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode(['process:read'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @return array{
 *     caller: Node,
 *     appNode: Node,
 *     app: Project,
 *     instance: AppInstance,
 *     process: Process,
 *     hostname: string
 * }
 */
function processStreamAppFixture(): array
{
    $caller = createProcessStreamCallerNode();
    $appNode = createTestAppHostNode(['name' => 'app-1']);
    grantProcessStreamAccess($caller, $appNode);
    $app = Project::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
    $instance = AppInstance::factory()->create([
        'app_id' => $app->id,
        'name' => 'development',
        'driver_config' => new OrbitAppInstanceDriverConfigData(node_id: $appNode->id),
    ]);
    ProxyRoute::factory()->create([
        'node_id' => $appNode->id,
        'domain' => 'test.app.example',
        'app_id' => $app->id,
        'owner_type' => 'app',
        'kind' => 'app',
        'config' => [
            'app_instance' => [
                'name' => 'development',
                'selector' => 'docs.development',
            ],
        ],
    ]);
    $process = Process::factory()
        ->forOwner($app, $appNode)
        ->create([
            'app_instance_id' => $instance->id,
            'name' => 'vite',
        ]);

    return [
        'caller' => $caller,
        'appNode' => $appNode,
        'app' => $app,
        'instance' => $instance,
        'process' => $process,
        'hostname' => 'test.app.example',
    ];
}

function bindProcessStreamSnapshotOnly(): void
{
    app()->instance(
        ProcessStreamRuntimeConfig::class,
        new ProcessStreamRuntimeConfig(
            pollMicroseconds: 0,
            heartbeatMicroseconds: 1_000_000_000,
            maxIdlePolls: 0,
        ),
    );
}

/**
 * @param  array<string, string>  $server
 * @param  array<string, string>  $query
 */
function processStreamRequest(string $app, array $server = [], array $query = []): TestResponse
{
    return test()->call(
        'GET',
        '/api/processes/stream',
        array_merge(['app' => $app], $query),
        [],
        [],
        [
            'REMOTE_ADDR' => PROCESS_STREAM_CALLER_WG_IP,
            ...$server,
        ],
    );
}

describe('ProcessStreamController', function (): void {
    it('requires the app hostname selector', function (): void {
        createProcessStreamCallerNode();

        $this
            ->call(
                'GET',
                '/api/processes/stream',
                [],
                [],
                [],
                ['REMOTE_ADDR' => PROCESS_STREAM_CALLER_WG_IP],
            )
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', 'app');
    });

    it('rejects non-app query selectors including historical last_event_id knobs', function (): void {
        $fixture = processStreamAppFixture();

        $this
            ->call(
                'GET',
                '/api/processes/stream',
                [
                    'app' => $fixture['hostname'],
                    'last_event_id' => '1',
                ],
                [],
                [],
                ['REMOTE_ADDR' => PROCESS_STREAM_CALLER_WG_IP],
            )
            ->assertStatus(400)
            ->assertJsonPath('error.meta.field', 'last_event_id')
            ->assertJsonPath('error.meta.reason', 'stream_app_only');
    });

    it('rejects url selectors', function (): void {
        createProcessStreamCallerNode();

        $this
            ->call(
                'GET',
                '/api/processes/stream',
                [
                    'app' => 'test.app.example',
                    'url' => 'https://test.app.example',
                ],
                [],
                [],
                ['REMOTE_ADDR' => PROCESS_STREAM_CALLER_WG_IP],
            )
            ->assertStatus(400)
            ->assertJsonPath('error.meta.reason', 'stream_app_only');
    });

    it('rejects unknown WireGuard peer identity', function (): void {
        processStreamAppFixture();

        $this
            ->call(
                'GET',
                '/api/processes/stream',
                ['app' => 'test.app.example'],
                [],
                [],
                ['REMOTE_ADDR' => '10.6.0.222'],
            )
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');
    });

    it('requires process:read grant and keeps CORS after Origin admission', function (): void {
        createProcessStreamCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1']);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'test.app.example',
            'app_id' => $app->id,
            'owner_type' => 'app',
            'kind' => 'app',
        ]);

        $response = $this->call(
            'GET',
            '/api/processes/stream',
            ['app' => 'test.app.example'],
            [],
            [],
            [
                'REMOTE_ADDR' => PROCESS_STREAM_CALLER_WG_IP,
                'HTTP_ORIGIN' => 'https://test.app.example',
            ],
        );

        $response
            ->assertForbidden()
            ->assertHeader('Access-Control-Allow-Origin', 'https://test.app.example')
            ->assertJsonPath('error.code', 'authorization_failed');
    });

    it('does not require X-Orbit-Client for the stream', function (): void {
        $fixture = processStreamAppFixture();
        bindProcessStreamSnapshotOnly();

        $server = [
            'REMOTE_ADDR' => PROCESS_STREAM_CALLER_WG_IP,
            'HTTP_ORIGIN' => 'https://test.app.example',
        ];

        expect($server)->not->toHaveKey('HTTP_X_ORBIT_CLIENT');

        $response = processStreamRequest($fixture['hostname'], $server);

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')
            ->assertHeader('X-Accel-Buffering', 'no');

        expect($response->headers->get('Cache-Control') ?? '')->toContain('no-cache');
    });

    it('emits a snapshot with high-water SSE id 0 when no events exist', function (): void {
        $fixture = processStreamAppFixture();
        bindProcessStreamSnapshotOnly();

        $content = processStreamRequest($fixture['hostname'])->streamedContent();

        expect($content)
            ->toContain("id: 0\n")
            ->toContain("event: snapshot\n")
            ->toContain('"high_water_mark":0')
            ->toContain('"name":"vite"')
            ->toContain('"status":"unknown"');
    });

    it('assigns the durable high-water mark as the snapshot SSE id', function (): void {
        $fixture = processStreamAppFixture();
        $event = ProcessEvent::factory()->create([
            'event' => ProcessEventType::Started,
            'process_id' => $fixture['process']->id,
            'process_name' => 'vite',
            'app_id' => $fixture['app']->id,
            'app_instance_id' => $fixture['instance']->id,
            'workspace_id' => null,
            'node_id' => $fixture['appNode']->id,
            'unit_name' => 'orbit_docs_development_main_vite',
        ]);
        bindProcessStreamSnapshotOnly();

        $content = processStreamRequest($fixture['hostname'])->streamedContent();

        expect($content)
            ->toContain("id: {$event->id}\n")
            ->toContain("event: snapshot\n")
            ->toContain('"high_water_mark":'.$event->id)
            ->toContain('"status":"running"');
    });

    it('accepts Last-Event-ID reconnect without replaying pre-snapshot history', function (): void {
        $fixture = processStreamAppFixture();
        $old = ProcessEvent::factory()->create([
            'event' => ProcessEventType::Started,
            'process_id' => $fixture['process']->id,
            'process_name' => 'vite',
            'app_id' => $fixture['app']->id,
            'app_instance_id' => $fixture['instance']->id,
            'workspace_id' => null,
            'node_id' => $fixture['appNode']->id,
            'unit_name' => 'orbit_docs_development_main_vite',
            'recorded_at' => now()->subMinutes(2),
        ]);
        $latest = ProcessEvent::factory()->create([
            'event' => ProcessEventType::Stopped,
            'process_id' => $fixture['process']->id,
            'process_name' => 'vite',
            'app_id' => $fixture['app']->id,
            'app_instance_id' => $fixture['instance']->id,
            'workspace_id' => null,
            'node_id' => $fixture['appNode']->id,
            'unit_name' => 'orbit_docs_development_main_vite',
            'recorded_at' => now(),
        ]);
        bindProcessStreamSnapshotOnly();

        $content = processStreamRequest(
            $fixture['hostname'],
            ['HTTP_LAST_EVENT_ID' => (string) $old->id],
        )->streamedContent();

        expect($content)
            ->toContain("id: {$latest->id}\n")
            ->toContain("event: snapshot\n")
            ->toContain('"status":"stopped"')
            ->and($content)
            ->not->toContain("event: update\n");
    });

    it('streams ordered updates after the connect high-water mark', function (): void {
        $fixture = processStreamAppFixture();
        $baseline = ProcessEvent::factory()->create([
            'event' => ProcessEventType::Started,
            'process_id' => $fixture['process']->id,
            'process_name' => 'vite',
            'app_id' => $fixture['app']->id,
            'app_instance_id' => $fixture['instance']->id,
            'workspace_id' => null,
            'node_id' => $fixture['appNode']->id,
            'unit_name' => 'orbit_docs_development_main_vite',
        ]);

        $createdIds = [];
        app()->instance(ProcessStreamSleeper::class, new class($fixture, $createdIds) implements ProcessStreamSleeper {
            /**
             * @param  array{process: Process, app: Project, instance: AppInstance, appNode: Node}  $fixture
             * @param  list<int>  $createdIds
             */
            public function __construct(
                private array $fixture,
                private array &$createdIds,
            ) {}

            public function sleep(int $microseconds): void
            {
                if ($this->createdIds !== []) {
                    return;
                }

                foreach ([ProcessEventType::Stopping, ProcessEventType::Stopped] as $type) {
                    $event = ProcessEvent::factory()->create([
                        'event' => $type,
                        'process_id' => $this->fixture['process']->id,
                        'process_name' => 'vite',
                        'app_id' => $this->fixture['app']->id,
                        'app_instance_id' => $this->fixture['instance']->id,
                        'workspace_id' => null,
                        'node_id' => $this->fixture['appNode']->id,
                        'unit_name' => 'orbit_docs_development_main_vite',
                    ]);
                    $this->createdIds[] = $event->id;
                }
            }
        });
        app()->instance(
            ProcessStreamRuntimeConfig::class,
            new ProcessStreamRuntimeConfig(
                pollMicroseconds: 0,
                heartbeatMicroseconds: 1_000_000_000,
                maxIdlePolls: 3,
            ),
        );

        $content = processStreamRequest($fixture['hostname'])->streamedContent();

        expect($content)
            ->toContain("id: {$baseline->id}\n")
            ->toContain("event: snapshot\n")
            ->toContain("event: update\n")
            ->toContain('"event":"stopping"')
            ->toContain('"status":"stopping"')
            ->toContain('"event":"stopped"')
            ->toContain('"status":"stopped"')
            ->toContain("id: {$createdIds[0]}\n")
            ->toContain("id: {$createdIds[1]}\n");

        $stoppingPos = mb_strpos($content, '"event":"stopping"');
        $stoppedPos = mb_strpos($content, '"event":"stopped"');

        expect($stoppingPos)
            ->not->toBeFalse()->and($stoppedPos)
            ->not->toBeFalse()->and($stoppingPos)->toBeLessThan((int) $stoppedPos);
    });

    it('streams events for a process configured after the browser connected', function (): void {
        $fixture = processStreamAppFixture();
        $createdId = null;

        app()->instance(ProcessStreamSleeper::class, new class($fixture, $createdId) implements ProcessStreamSleeper {
            /**
             * @param  array{app: Project, instance: AppInstance, appNode: Node}  $fixture
             */
            public function __construct(
                private array $fixture,
                private ?int &$createdId,
            ) {}

            public function sleep(int $microseconds): void
            {
                if ($this->createdId !== null) {
                    return;
                }

                $process = Process::factory()
                    ->forOwner($this->fixture['app'], $this->fixture['appNode'])
                    ->create([
                        'app_instance_id' => $this->fixture['instance']->id,
                        'name' => 'queue',
                    ]);

                $event = ProcessEvent::factory()->create([
                    'event' => ProcessEventType::Starting,
                    'process_id' => $process->id,
                    'process_name' => 'queue',
                    'app_id' => $this->fixture['app']->id,
                    'app_instance_id' => $this->fixture['instance']->id,
                    'workspace_id' => null,
                    'node_id' => $this->fixture['appNode']->id,
                    'unit_name' => 'orbit_docs_development_main_queue',
                ]);
                $this->createdId = $event->id;
            }
        });
        app()->instance(
            ProcessStreamRuntimeConfig::class,
            new ProcessStreamRuntimeConfig(
                pollMicroseconds: 0,
                heartbeatMicroseconds: 1_000_000_000,
                maxIdlePolls: 3,
            ),
        );

        $content = processStreamRequest($fixture['hostname'])->streamedContent();

        expect($createdId)
            ->not
            ->toBeNull()
            ->and($content)
            ->toContain("event: snapshot\n")
            ->toContain("event: update\n")
            ->toContain('"name":"queue"')
            ->toContain('"event":"starting"')
            ->toContain('"status":"starting"')
            ->toContain("id: {$createdId}\n");
    });

    it('streams workspace-hostname context with workspace-scoped snapshot and updates', function (): void {
        $caller = createProcessStreamCallerNode();
        $appNode = createTestAppHostNode(['name' => 'app-1']);
        grantProcessStreamAccess($caller, $appNode);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        $instance = AppInstance::factory()->create([
            'app_id' => $app->id,
            'name' => 'development',
            'driver_config' => new OrbitAppInstanceDriverConfigData(node_id: $appNode->id),
        ]);
        $workspace = Workspace::factory()->create([
            'name' => 'feature-docs',
            'app_id' => $app->id,
            'app_instance_id' => $instance->id,
        ]);
        ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'feature-docs.app.example',
            'app_id' => $app->id,
            'workspace_id' => $workspace->id,
            'owner_type' => 'workspace',
            'kind' => 'workspace',
        ]);
        $process = Process::factory()
            ->forOwner($app, $appNode)
            ->create([
                'app_instance_id' => $instance->id,
                'name' => 'vite',
            ]);
        ProcessEvent::factory()->create([
            'event' => ProcessEventType::Started,
            'process_id' => $process->id,
            'process_name' => 'vite',
            'app_id' => $app->id,
            'app_instance_id' => $instance->id,
            'workspace_id' => $workspace->id,
            'node_id' => $appNode->id,
            'unit_name' => 'orbit_docs_development_feature-docs_vite',
        ]);
        // Instance-level event must not appear in workspace stream scope.
        ProcessEvent::factory()->create([
            'event' => ProcessEventType::Stopped,
            'process_id' => $process->id,
            'process_name' => 'vite',
            'app_id' => $app->id,
            'app_instance_id' => $instance->id,
            'workspace_id' => null,
            'node_id' => $appNode->id,
            'unit_name' => 'orbit_docs_development_main_vite',
        ]);

        $createdId = null;
        app()->instance(ProcessStreamSleeper::class, new class(
            $process,
            $app,
            $instance,
            $workspace,
            $appNode,
            $createdId,
        ) implements ProcessStreamSleeper {
            public function __construct(
                private Process $process,
                private Project $app,
                private AppInstance $instance,
                private Workspace $workspace,
                private Node $appNode,
                private ?int &$createdId,
            ) {}

            public function sleep(int $microseconds): void
            {
                if ($this->createdId !== null) {
                    return;
                }

                $event = ProcessEvent::factory()->create([
                    'event' => ProcessEventType::Stopping,
                    'process_id' => $this->process->id,
                    'process_name' => 'vite',
                    'app_id' => $this->app->id,
                    'app_instance_id' => $this->instance->id,
                    'workspace_id' => $this->workspace->id,
                    'node_id' => $this->appNode->id,
                    'unit_name' => 'orbit_docs_development_feature-docs_vite',
                ]);
                $this->createdId = $event->id;
            }
        });
        app()->instance(
            ProcessStreamRuntimeConfig::class,
            new ProcessStreamRuntimeConfig(
                pollMicroseconds: 0,
                heartbeatMicroseconds: 1_000_000_000,
                maxIdlePolls: 3,
            ),
        );

        $content = processStreamRequest('feature-docs.app.example')->streamedContent();

        expect($content)
            ->toContain("event: snapshot\n")
            ->toContain('"workspace":"feature-docs"')
            ->toContain('"name":"vite"')
            ->toContain('"status":"running"')
            ->toContain("event: update\n")
            ->toContain('"event":"stopping"')
            ->toContain('"status":"stopping"')
            ->toContain('"name":"vite"')
            ->and($createdId)
            ->not->toBeNull()->and($content)->toContain("id: {$createdId}\n")->and($content)
            ->not->toContain('"event":"stopped"');
    });

    it('emits event:error when the follow loop fails and ends the stream', function (): void {
        $fixture = processStreamAppFixture();

        app()->instance(ProcessStreamSleeper::class, new class implements ProcessStreamSleeper {
            public function sleep(int $microseconds): void
            {
                throw new RuntimeException('forced stream failure');
            }
        });
        app()->instance(
            ProcessStreamRuntimeConfig::class,
            new ProcessStreamRuntimeConfig(
                pollMicroseconds: 0,
                heartbeatMicroseconds: 1_000_000_000,
                maxIdlePolls: 3,
            ),
        );

        $response = processStreamRequest($fixture['hostname']);
        $content = $response->streamedContent();

        $response->assertOk();

        expect($content)
            ->toContain("event: snapshot\n")
            ->toContain("event: error\n")
            ->toContain('"code":"process.event_stream_failed"')
            ->toContain('forced stream failure')
            // Stream completes after error (no hang): snapshot then terminal error.
            ->and(mb_strpos($content, 'event: error'))
            ->toBeGreaterThan((int) mb_strpos($content, 'event: snapshot'));
    });

    it('admits browser CORS preflight for the stream path including Last-Event-ID', function (): void {
        $appNode = createTestAppHostNode(['name' => 'app-1']);
        $app = Project::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        ProxyRoute::factory()->create([
            'node_id' => $appNode->id,
            'domain' => 'test.app.example',
            'app_id' => $app->id,
            'owner_type' => 'app',
            'kind' => 'app',
        ]);

        $response = $this->call(
            'OPTIONS',
            '/api/processes/stream',
            [],
            [],
            [],
            [
                'HTTP_ORIGIN' => 'https://test.app.example',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Last-Event-ID',
            ],
        );

        $response
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://test.app.example');

        expect($response->headers->get('Access-Control-Allow-Headers') ?? '')
            ->toContain('Last-Event-ID');
    });
});

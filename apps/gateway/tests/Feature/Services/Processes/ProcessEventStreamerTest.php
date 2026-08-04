<?php

declare(strict_types=1);

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Enums\ProcessEventType;
use App\Models\AppInstance;
use App\Models\Process;
use App\Models\ProcessEvent;
use App\Models\Project;
use App\Services\Processes\ProcessEventStreamer;
use App\Services\Processes\ProcessStreamClock;
use App\Services\Processes\ProcessStreamConnection;
use App\Services\Processes\ProcessStreamRuntimeConfig;
use App\Services\Processes\ProcessStreamScope;
use App\Services\Processes\ProcessStreamSleeper;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns high-water mark 0 when the scope has no durable events', function (): void {
    $scope = processStreamTestScope();

    expect(app(ProcessEventStreamer::class)->highWaterMark($scope))->toBe(0);
});

it('returns the max durable event id in the app-instance workspace-null node scope', function (): void {
    $setup = processStreamTestFixture();
    $older = ProcessEvent::factory()->create([
        'event' => ProcessEventType::Started,
        'process_id' => $setup['process']->id,
        'process_name' => 'vite',
        'app_id' => $setup['app']->id,
        'app_instance_id' => $setup['instance']->id,
        'workspace_id' => null,
        'node_id' => $setup['node']->id,
        'unit_name' => 'orbit_docs_development_main_vite',
        'recorded_at' => now()->subMinute(),
    ]);
    $newer = ProcessEvent::factory()->create([
        'event' => ProcessEventType::Stopped,
        'process_id' => $setup['process']->id,
        'process_name' => 'vite',
        'app_id' => $setup['app']->id,
        'app_instance_id' => $setup['instance']->id,
        'workspace_id' => null,
        'node_id' => $setup['node']->id,
        'unit_name' => 'orbit_docs_development_main_vite',
        'recorded_at' => now(),
    ]);
    ProcessEvent::factory()->create([
        'event' => ProcessEventType::Started,
        'process_id' => $setup['process']->id,
        'process_name' => 'vite',
        'app_id' => $setup['app']->id,
        'app_instance_id' => $setup['otherInstance']->id,
        'workspace_id' => null,
        'node_id' => $setup['node']->id,
        'unit_name' => 'other',
    ]);

    expect(app(ProcessEventStreamer::class)->highWaterMark($setup['scope']))
        ->toBe($newer->id)
        ->and($newer->id)
        ->toBeGreaterThan($older->id);
});

it('follows only events after the high-water mark and never replays earlier rows', function (): void {
    $setup = processStreamTestFixture();
    $past = ProcessEvent::factory()->create([
        'event' => ProcessEventType::Started,
        'process_id' => $setup['process']->id,
        'process_name' => 'vite',
        'app_id' => $setup['app']->id,
        'app_instance_id' => $setup['instance']->id,
        'workspace_id' => null,
        'node_id' => $setup['node']->id,
        'unit_name' => 'orbit_docs_development_main_vite',
    ]);
    $highWater = $past->id;
    $future = ProcessEvent::factory()->create([
        'event' => ProcessEventType::Stopping,
        'process_id' => $setup['process']->id,
        'process_name' => 'vite',
        'app_id' => $setup['app']->id,
        'app_instance_id' => $setup['instance']->id,
        'workspace_id' => null,
        'node_id' => $setup['node']->id,
        'unit_name' => 'orbit_docs_development_main_vite',
    ]);

    $frames = iterator_to_array(
        app(ProcessEventStreamer::class)->follow(
            scope: $setup['scope'],
            afterId: $highWater,
            config: new ProcessStreamRuntimeConfig(
                pollMicroseconds: 0,
                heartbeatMicroseconds: 1_000_000_000,
                maxIdlePolls: 0,
            ),
        ),
        false,
    );

    expect($frames)
        ->toHaveCount(1)
        ->and($frames[0]->id)
        ->toBe($future->id)
        ->and(collect($frames)->pluck('id')->all())
        ->not->toContain($past->id);
});

it('emits durable events for a process configured after follow began', function (): void {
    $setup = processStreamTestFixture();
    $highWater = app(ProcessEventStreamer::class)->highWaterMark($setup['scope']);
    $polls = 0;
    $createdEventId = null;

    app()->instance(ProcessStreamSleeper::class, new class($setup, $polls, $createdEventId) implements
        ProcessStreamSleeper {
        /**
         * @param  array{app: Project, instance: AppInstance, node: \App\Models\Node}  $setup
         */
        public function __construct(
            private array $setup,
            private int &$polls,
            private ?int &$createdEventId,
        ) {}

        public function sleep(int $microseconds): void
        {
            $this->polls++;

            if ($this->polls !== 1) {
                return;
            }

            $process = Process::factory()
                ->forOwner($this->setup['app'], $this->setup['node'])
                ->create([
                    'app_instance_id' => $this->setup['instance']->id,
                    'name' => 'queue',
                ]);

            $event = ProcessEvent::factory()->create([
                'event' => ProcessEventType::Starting,
                'process_id' => $process->id,
                'process_name' => 'queue',
                'app_id' => $this->setup['app']->id,
                'app_instance_id' => $this->setup['instance']->id,
                'workspace_id' => null,
                'node_id' => $this->setup['node']->id,
                'unit_name' => 'orbit_docs_development_main_queue',
            ]);
            $this->createdEventId = $event->id;
        }
    });

    $streamer = app(ProcessEventStreamer::class);
    $frames = [];

    foreach ($streamer->follow(
        scope: $setup['scope'],
        afterId: $highWater,
        config: new ProcessStreamRuntimeConfig(
            pollMicroseconds: 0,
            heartbeatMicroseconds: 1_000_000_000,
            maxIdlePolls: 2,
        ),
    ) as $frame) {
        $frames[] = $frame;

        if ($frame instanceof ProcessEvent) {
            break;
        }
    }

    expect($createdEventId)
        ->not->toBeNull()->and($frames)
        ->not->toBeEmpty()->and($frames[0])->toBeInstanceOf(ProcessEvent::class)->and($frames[0]->id)->toBe(
            $createdEventId,
        )->and($frames[0]->event)->toBe(ProcessEventType::Starting)->and($frames[0]->process_name)->toBe('queue');
});

it('emits heartbeats on the heartbeat cadence independent of DB poll cadence', function (): void {
    $setup = processStreamTestFixture();
    $sleeps = 0;
    $nowSeconds = 1000.0;

    app()->instance(ProcessStreamClock::class, new class($nowSeconds) implements ProcessStreamClock {
        public function __construct(
            private float &$nowSeconds,
        ) {}

        public function now(): float
        {
            return $this->nowSeconds;
        }
    });

    app()->instance(ProcessStreamSleeper::class, new class($sleeps, $nowSeconds) implements ProcessStreamSleeper {
        public function __construct(
            private int &$sleeps,
            private float &$nowSeconds,
        ) {}

        public function sleep(int $microseconds): void
        {
            $this->sleeps++;
            // Advance 400ms per poll so 1s heartbeat fires once across five polls.
            $this->nowSeconds += 0.4;
        }
    });

    $frames = iterator_to_array(
        app(ProcessEventStreamer::class)->follow(
            scope: $setup['scope'],
            afterId: 0,
            config: new ProcessStreamRuntimeConfig(
                pollMicroseconds: 0,
                heartbeatMicroseconds: 1_000_000,
                maxIdlePolls: 5,
            ),
        ),
        false,
    );

    $heartbeats = array_values(array_filter(
        $frames,
        static fn (mixed $frame): bool => $frame === 'heartbeat',
    ));

    // polls at t=0,0.4,0.8,1.2,1.6 then exit when idlePolls reaches 5 after fifth sleep;
    // heartbeat only when elapsed >= 1.0 (at t=1.2).
    expect($sleeps)
        ->toBe(5)
        ->and($heartbeats)
        ->toHaveCount(1)
        ->and(count($frames))
        ->toBe(1);
});

it('stops following without further sleeps after disconnect', function (): void {
    $setup = processStreamTestFixture();
    $sleeps = 0;
    $aborted = false;

    app()->instance(ProcessStreamConnection::class, new class($aborted) implements ProcessStreamConnection {
        public function __construct(
            private bool &$aborted,
        ) {}

        public function aborted(): bool
        {
            return $this->aborted;
        }
    });

    app()->instance(ProcessStreamSleeper::class, new class($sleeps, $aborted) implements ProcessStreamSleeper {
        public function __construct(
            private int &$sleeps,
            private bool &$aborted,
        ) {}

        public function sleep(int $microseconds): void
        {
            $this->sleeps++;
            // Disconnect after the first idle sleep.
            $this->aborted = true;
        }
    });

    // Wrap eventsAfter via subclassing is not possible (final). Count sleeps and that follow ends.
    $frames = iterator_to_array(
        app(ProcessEventStreamer::class)->follow(
            scope: $setup['scope'],
            afterId: 0,
            config: new ProcessStreamRuntimeConfig(
                pollMicroseconds: 0,
                heartbeatMicroseconds: 1_000_000_000,
                maxIdlePolls: 50,
            ),
        ),
        false,
    );

    expect($sleeps)
        ->toBe(1)
        ->and($frames)
        ->toBeEmpty()
        ->and($aborted)
        ->toBeTrue();
});

/**
 * @return array{
 *     app: Project,
 *     instance: AppInstance,
 *     otherInstance: AppInstance,
 *     node: \App\Models\Node,
 *     process: Process,
 *     scope: ProcessStreamScope
 * }
 */
function processStreamTestFixture(): array
{
    $node = createTestAppHostNode(['name' => 'app-1']);
    $app = Project::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
    $instance = AppInstance::factory()->create([
        'app_id' => $app->id,
        'name' => 'development',
        'driver_config' => new OrbitAppInstanceDriverConfigData(node_id: $node->id),
    ]);
    $otherInstance = AppInstance::factory()->create([
        'app_id' => $app->id,
        'name' => 'production',
        'driver_config' => new OrbitAppInstanceDriverConfigData(node_id: $node->id),
    ]);
    $process = Process::factory()
        ->forOwner($app, $node)
        ->create([
            'app_instance_id' => $instance->id,
            'name' => 'vite',
        ]);

    return [
        'app' => $app,
        'instance' => $instance,
        'otherInstance' => $otherInstance,
        'node' => $node,
        'process' => $process,
        'scope' => new ProcessStreamScope(
            appInstanceId: $instance->id,
            workspaceId: null,
            nodeId: $node->id,
        ),
    ];
}

function processStreamTestScope(): ProcessStreamScope
{
    return processStreamTestFixture()['scope'];
}

<?php

declare(strict_types=1);

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\AppInstance;
use App\Models\DeployStep;
use App\Models\Node;
use App\Models\OperationEvent;
use App\Models\OperationRun;
use App\Models\Project;
use App\Services\Deploy\DeployOperationRunner;
use App\Services\Operations\OperationStreamFrameBroadcaster;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Core\Http\JsonEnvelope;

uses(RefreshDatabase::class);

it('persists deploy progress frames before WebSocket publication and supports journal replay', function (): void {
    $node = Node::factory()->appProd()->create(['name' => 'app-prod-1']);
    $app = Project::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'environment' => 'production',
        'path' => '/srv/docs',
        'runtime' => 'static',
    ]);
    $instance = AppInstance::factory()->create([
        'app_id' => $app->id,
        'name' => 'production',
        'driver_config' => new OrbitAppInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/srv/docs',
            document_root: 'public',
        ),
    ]);
    DeployStep::query()->create([
        'app_instance_id' => $instance->id,
        'title' => 'Pull source',
        'command' => 'git pull origin main',
        'sort_order' => 1,
        'timeout_seconds' => 120,
    ]);
    $caller = Node::factory()->create(['name' => 'operator']);

    app()->instance(RunsInternalCommands::class, new class implements RunsInternalCommands {
        public function runInternal(
            Node $node,
            string $commandName,
            array $arguments = [],
            array $commandOptions = [],
            array $transportOptions = [],
        ): RemoteShellResult {
            return new RemoteShellResult(
                exitCode: 0,
                stdout: json_encode(JsonEnvelope::success([
                    'exit_code' => 0,
                    'stdout' => "updated\n",
                    'stderr' => '',
                    'duration_ms' => 25,
                ]), JSON_THROW_ON_ERROR),
                stderr: '',
                durationMs: 25,
            );
        }
    });

    $broadcaster = new class extends OperationStreamFrameBroadcaster {
        /** @var list<array<string, mixed>> */
        public array $frames = [];

        public function broadcast(string $channel, array $frame): void
        {
            $eventId = data_get($frame, 'durable_replay_cursor.event_id');

            expect($eventId)
                ->toBeInt()
                ->and(OperationEvent::query()->whereKey($eventId)->exists())
                ->toBeTrue();

            $this->frames[] = $frame;
        }
    };
    app()->instance(OperationStreamFrameBroadcaster::class, $broadcaster);

    $runner = app(DeployOperationRunner::class);
    $descriptor = $runner->start('docs', $caller);
    $runner->execute($descriptor['uuid'], 'docs');

    $run = OperationRun::query()->findOrFail($descriptor['uuid']);
    $events = OperationEvent::query()
        ->where('operation_run_id', $run->id)
        ->orderBy('sequence')
        ->get();
    $frameTypes = $events
        ->map(fn (OperationEvent $event): mixed => data_get($event->payload, 'frame.type'))
        ->all();

    expect($run->status->value)
        ->toBe('succeeded')
        ->and($events->pluck('event_type')->unique()->all())
        ->toBe(['operation_stream.frame'])
        ->and($frameTypes)
        ->toContain('tree', 'step', 'complete')
        ->and($broadcaster->frames)
        ->toHaveCount($events->count());
});

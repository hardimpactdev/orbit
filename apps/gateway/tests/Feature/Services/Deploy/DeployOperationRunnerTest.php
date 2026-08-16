<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\DeployStep;
use App\Models\Instance;
use App\Models\Node;
use App\Models\OperationEvent;
use App\Models\OperationRun;
use App\Services\Deploy\DeployOperationRunner;
use App\Services\Operations\OperationStreamFrameBroadcaster;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Core\Http\JsonEnvelope;
use Orbit\Core\Operations\DurableOperationStreamFrame;

uses(RefreshDatabase::class);

it('persists deploy progress frames before WebSocket publication and supports journal replay', function (): void {
    $node = Node::factory()->appProd()->create(['name' => 'app-prod-1']);
    $app = App::factory()->create([
        'name' => 'docs',
        'runtime' => 'static',
    ]);
    $instance = Instance::factory()->create([
        'app_id' => $app->id,
        'name' => 'production',
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            node: $node->name,
            path: '/srv/docs',
            document_root: 'public',
            domain: 'docs.example.com',
        ),
    ]);
    DeployStep::query()->create([
        'instance_id' => $instance->id,
        'title' => 'Activate release',
        'command' => 'ln -sfn "{{ release_path }}" "{{ live_path }}"',
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

        public function broadcast(string $channel, DurableOperationStreamFrame $frame): void
        {
            $frameArray = $frame->toArray();
            $eventId = $frame->cursor()->eventId;
            $event = OperationEvent::query()->findOrFail($eventId);

            expect($eventId)
                ->toBeInt()
                ->and($frame->cursor()->eventSequence)
                ->toBe($event->sequence)
                ->and($event->payload['frame'])
                ->toBe($frameArray);

            $this->frames[] = $frameArray;
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
    $treeStepKeys = $events
        ->first(fn (OperationEvent $event): bool => data_get($event->payload, 'frame.type') === 'tree')
        ?->payload['frame']['payload']['steps'] ?? [];
    $treeStepKeys = array_column($treeStepKeys, 'key');

    expect($run->status->value)
        ->toBe('succeeded')
        ->and($events->pluck('event_type')->unique()->all())
        ->toBe(['operation_stream.frame'])
        ->and($frameTypes)
        ->toContain('tree', 'step', 'complete')
        ->and($treeStepKeys)
        ->not
        ->toContain('activate-runtime')
        ->and($broadcaster->frames)
        ->toHaveCount($events->count());
});

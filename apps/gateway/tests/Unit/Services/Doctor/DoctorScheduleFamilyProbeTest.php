<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Schedule;
use App\Services\Doctor\DoctorScheduleFamilyProbe;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('keeps node scope, row order, issue details, and progress inside the schedule family', function (): void {
    /** @var Node $node */
    $node = Node::factory()->create(['name' => 'schedule-family-node', 'status' => 'active']);
    /** @var Node $otherNode */
    $otherNode = Node::factory()->create(['name' => 'other-schedule-node', 'status' => 'active']);
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'app-dev',
        'status' => 'active',
    ]);
    NodeRoleAssignment::factory()->create([
        'node_id' => $otherNode->id,
        'role' => 'app-dev',
        'status' => 'active',
    ]);
    /** @var Schedule $firstSchedule */
    $firstSchedule = Schedule::factory()
        ->create([
            'name' => 'zulu',
            'scope' => 'node',
            'app_id' => null,
            'instance_id' => null,
            'node_id' => $node->id,
            'target_name' => $node->name,
            'schedule_key' => "node:{$node->name}:zulu",
            'execution_value' => '',
        ]);
    /** @var Schedule $secondSchedule */
    $secondSchedule = Schedule::factory()
        ->create([
            'name' => 'alpha',
            'scope' => 'node',
            'app_id' => null,
            'instance_id' => null,
            'node_id' => $node->id,
            'target_name' => $node->name,
            'schedule_key' => "node:{$node->name}:alpha",
            'execution_value' => '',
        ]);
    Schedule::factory()
        ->create([
            'name' => 'excluded',
            'scope' => 'node',
            'app_id' => null,
            'instance_id' => null,
            'node_id' => $otherNode->id,
            'target_name' => $otherNode->name,
            'schedule_key' => "node:{$otherNode->name}:excluded",
            'execution_value' => '',
        ]);
    app()->instance(RunsInternalCommands::class, new DoctorScheduleFamilyExecutor);
    $events = [];

    $issues = app(DoctorScheduleFamilyProbe::class)->probe(
        node: $node,
        key: null,
        onFamilyProgress: static function (
            string $family,
            string $phase,
            array $issues,
            ?int $completed,
            ?int $total,
        ) use (&$events): void {
            $events[] = compact('family', 'phase', 'issues', 'completed', 'total');
        },
    );

    expect(collect($issues)->pluck('detail.schedule_key')->all())
        ->toBe([$firstSchedule->schedule_key, $secondSchedule->schedule_key])
        ->and(collect($issues)->pluck('node')->unique()->values()->all())
        ->toBe([$node->name])
        ->and(collect($events)->pluck('family')->unique()->values()->all())
        ->toBe(['schedule'])
        ->and(collect($events)->pluck('phase')->all())
        ->toBe(['running', 'running', 'done'])
        ->and(collect($events)->pluck('completed')->all())
        ->toBe([0, 1, null])
        ->and(collect($events)->pluck('total')->all())
        ->toBe([2, 2, null]);
});

/** @mago-expect lint:file-name */
final readonly class DoctorScheduleFamilyExecutor implements RunsInternalCommands
{
    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        return new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 1,
        );
    }
}

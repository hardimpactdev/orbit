<?php

declare(strict_types=1);

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Doctor\DoctorToolFamilyProbe;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('keeps node scope, row order, issue details, and progress inside the tool family', function (): void {
    /** @var Node $node */
    $node = Node::factory()->create(['name' => 'tool-family-node', 'status' => 'inactive']);
    /** @var Node $otherNode */
    $otherNode = Node::factory()->create(['name' => 'other-tool-node', 'status' => 'inactive']);
    NodeTool::factory()->for($node)->create(['name' => 'git']);
    NodeTool::factory()->for($node)->create(['name' => 'composer']);
    NodeTool::factory()->for($otherNode)->create(['name' => 'gh']);
    app()->instance(RunsInternalCommands::class, new DoctorToolFamilyExecutor);
    $events = [];

    $issues = app(DoctorToolFamilyProbe::class)->probe(
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

    expect(collect($issues)->pluck('detail.tool')->filter()->unique()->values()->all())
        ->toBe(['git', 'composer'])
        ->and(collect($events)->pluck('family')->unique()->values()->all())
        ->toBe(['tool'])
        ->and(collect($events)->pluck('phase')->all())
        ->toBe(['running', 'running', 'done'])
        ->and(collect($events)->pluck('completed')->all())
        ->toBe([0, 1, null])
        ->and(collect($events)->pluck('total')->all())
        ->toBe([2, 2, null]);
});

/** @mago-expect lint:file-name */
final readonly class DoctorToolFamilyExecutor implements RunsInternalCommands
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
            stdout: json_encode([
                'success' => [
                    'data' => [
                        'exit_code' => 1,
                        'stdout' => '',
                        'stderr' => 'not installed',
                        'duration_ms' => 1,
                    ],
                    'meta' => [],
                ],
            ], JSON_THROW_ON_ERROR),
            stderr: '',
            durationMs: 1,
        );
    }
}

<?php

declare(strict_types=1);

use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Doctor\DoctorWorkspaceFamilyProbe;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('keeps node placement, row order, issue details, and progress inside the workspace family', function (): void {
    $node = createTestAppHostNode();
    $otherNode = createTestAppHostNode();
    /** @var App $app */
    $app = App::factory()->create(['name' => 'docs']);
    /** @var App $otherApp */
    $otherApp = App::factory()->create(['name' => 'other']);
    $instance = create_workspace_family_instance($app, $node);
    $otherInstance = create_workspace_family_instance($otherApp, $otherNode);
    Workspace::factory()
        ->for($app, 'app')
        ->for($instance, 'instance')
        ->create([
            'name' => 'zulu',
            'path' => "/home/orbit/apps/{$app->name}/.worktrees/zulu",
        ]);
    Workspace::factory()
        ->for($app, 'app')
        ->for($instance, 'instance')
        ->create([
            'name' => 'alpha',
            'path' => "/home/orbit/apps/{$app->name}/.worktrees/alpha",
        ]);
    Workspace::factory()
        ->for($otherApp, 'app')
        ->for($otherInstance, 'instance')
        ->create([
            'name' => 'excluded',
            'path' => "/home/orbit/apps/{$otherApp->name}/.worktrees/excluded",
        ]);
    app()->instance(RunsInternalCommands::class, new DoctorWorkspaceFamilyExecutor);
    $events = [];

    $issues = app(DoctorWorkspaceFamilyProbe::class)->probe(
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

    expect(collect($issues)->pluck('detail.workspace')->filter()->unique()->values()->all())
        ->toBe(['zulu', 'alpha'])
        ->and(collect($issues)->pluck('detail.app')->filter()->unique()->values()->all())
        ->toBe(['docs'])
        ->and(collect($events)->pluck('family')->unique()->values()->all())
        ->toBe(['workspace'])
        ->and(collect($events)->pluck('phase')->all())
        ->toBe(['running', 'running', 'done'])
        ->and(collect($events)->pluck('completed')->all())
        ->toBe([0, 1, null])
        ->and(collect($events)->pluck('total')->all())
        ->toBe([2, 2, null]);
});

/** @mago-expect lint:inline-variable-return */
function create_workspace_family_instance(App $app, Node $node): Instance
{
    /** @var Instance $instance */
    $instance = Instance::factory()->for($app)->create([
        'driver_config' => new OrbitInstanceDriverConfigData(
            node_id: $node->id,
            path: '/home/orbit/apps/'.$app->name,
            document_root: 'public',
            domain: null,
        ),
    ]);

    return $instance;
}

/** @mago-expect lint:file-name */
final readonly class DoctorWorkspaceFamilyExecutor implements RunsInternalCommands
{
    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        $input = $transportOptions['input'] ?? null;

        if (! is_string($input)) {
            throw new LogicException('Workspace family probe input is unavailable.');
        }

        /** @var array{script?: mixed} $payload */
        $payload = json_decode($input, associative: true, flags: JSON_THROW_ON_ERROR);

        if (! array_key_exists('script', $payload) || ! is_string($payload['script'])) {
            throw new LogicException('Workspace family probe script is unavailable.');
        }

        $matches = [];
        preg_match("/name='([^']+)'/", $payload['script'], $matches);
        $name = $matches[1] ?? '';

        return new RemoteShellResult(
            exitCode: 0,
            stdout: json_encode([
                'success' => [
                    'data' => [
                        'exit_code' => 0,
                        'stdout' => "{$name}\t0\t0\t0\t0\t0\t0\t0\t0\t0\t\n",
                        'stderr' => '',
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

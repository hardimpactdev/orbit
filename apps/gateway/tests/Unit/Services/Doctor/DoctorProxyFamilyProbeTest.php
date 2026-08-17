<?php

declare(strict_types=1);

use App\Data\Doctor\DoctorTargetScope;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\ProxyRoute;
use App\Services\Doctor\DoctorProxyFamilyProbe;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('keeps app scope, row order, issue payloads, and progress inside the proxy family', function (): void {
    /** @var Node $node */
    $node = Node::factory()->create([
        'name' => 'proxy-family-node',
        'status' => 'inactive',
        'platform' => 'ubuntu_24-04',
    ]);
    /** @var App $firstApp */
    $firstApp = App::factory()->create(['name' => 'first-app']);
    /** @var App $secondApp */
    $secondApp = App::factory()->create(['name' => 'second-app']);
    /** @var ProxyRoute $firstRoute */
    $firstRoute = ProxyRoute::factory()
        ->forApp($firstApp)
        ->create([
            'node_id' => $node->id,
            'app_id' => $firstApp->id,
            'domain' => 'zulu.example.test',
            'owner_type' => 'app',
            'kind' => 'app',
        ]);
    ProxyRoute::factory()
        ->forApp($secondApp)
        ->create([
            'node_id' => $node->id,
            'app_id' => $secondApp->id,
            'domain' => 'alpha.example.test',
            'owner_type' => 'app',
            'kind' => 'app',
        ]);
    app()->instance(RunsInternalCommands::class, new DoctorProxyFamilyExecutor);
    $events = [];

    $issues = app(DoctorProxyFamilyProbe::class)->probe(
        node: $node,
        scope: DoctorTargetScope::from(app: $firstApp->name, workspace: null),
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

    expect(collect($issues)->pluck('detail.domain')->filter()->unique()->values()->all())
        ->toBe([$firstRoute->domain])
        ->and(collect($events)->pluck('family')->unique()->values()->all())
        ->toBe(['proxy'])
        ->and(collect($events)->pluck('phase')->all())
        ->toBe(['running', 'running', 'running', 'done'])
        ->and(collect($events)->pluck('completed')->all())
        ->toBe([0, 1, 2, null])
        ->and(collect($events)->pluck('total')->all())
        ->toBe([3, 3, 3, null]);
});

/** @mago-expect lint:file-name */
final readonly class DoctorProxyFamilyExecutor implements RunsInternalCommands
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
                        'exit_code' => 0,
                        'stdout' => "0\t\t\t\t0\t0\t\t\t0\t\n",
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

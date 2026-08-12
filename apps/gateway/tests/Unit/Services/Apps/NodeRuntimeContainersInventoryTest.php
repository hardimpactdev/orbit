<?php

declare(strict_types=1);

use App\Data\Doctor\DriftEntry;
use App\Data\Doctor\ProbeSnapshot;
use App\Data\RemoteShell\RemoteShellResult;
use App\Enums\Apps\NodeRuntimeContainersProbeStatus;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Services\Apps\NodeRuntimeContainersInventory;
use App\Services\Apps\NodeRuntimeContainersProbe;
use App\Services\Apps\NodeRuntimeContainersProbeParser;
use App\Services\Apps\RemoteAppRuntimeContainersProbe;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function nodeRuntimeContainersInventory(string|Throwable $result): NodeRuntimeContainersInventory
{
    return new NodeRuntimeContainersInventory(
        new RemoteAppRuntimeContainersProbe(
            new NodeRuntimeContainersInventoryExecutor($result),
        ),
        new NodeRuntimeContainersProbeParser,
    );
}

/** @param list<DriftEntry> $drift */
function nodeRuntimeContainerIssue(array $drift, string $key): ?DriftEntry
{
    foreach ($drift as $entry) {
        if ($entry->key === $key) {
            return $entry;
        }
    }

    return null;
}

describe('node runtime container scan', function (): void {
    it('resolves through the application container', function (): void {
        expect(app(NodeRuntimeContainersInventory::class))
            ->toBeInstanceOf(NodeRuntimeContainersInventory::class);
    });

    it('parses present managed app containers in scan order', function (): void {
        $snapshot = new NodeRuntimeContainersProbeParser()->parse(implode("\n", [
            "orbit-app-docs\tdocs",
            'malformed row',
            'orbit-container-scan:present',
            "orbit-app-api\tapi",
            '',
        ]));

        expect($snapshot->status)
            ->toBe(NodeRuntimeContainersProbeStatus::Present)
            ->and($snapshot->error)
            ->toBe('')
            ->and($snapshot->containers->keys())
            ->toBe(['docs', 'api'])
            ->and($snapshot->containers->get('docs'))
            ->toBe([
                'container_name' => 'orbit-app-docs',
                'app_slug' => 'docs',
            ]);
    });

    it('uses the last row when a scan reports an app more than once', function (): void {
        $snapshot = new NodeRuntimeContainersProbeParser()->parse(implode("\n", [
            "orbit-app-docs-old\tdocs",
            "orbit-app-docs\tdocs",
            'orbit-container-scan:present',
            '',
        ]));

        expect($snapshot->containers->keys())
            ->toBe(['docs'])
            ->and($snapshot->containers->get('docs'))
            ->toBe([
                'container_name' => 'orbit-app-docs',
                'app_slug' => 'docs',
            ]);
    });

    it('drops parsed containers when the scan reports absent', function (): void {
        $snapshot = new NodeRuntimeContainersProbeParser()->parse(
            "orbit-app-docs\tdocs\norbit-container-scan:absent\n",
        );

        expect($snapshot->status)
            ->toBe(NodeRuntimeContainersProbeStatus::Absent)
            ->and($snapshot->containers->isEmpty())
            ->toBeTrue()
            ->and($snapshot->error)
            ->toBe('');
    });

    it('preserves an explicit scan error', function (): void {
        $snapshot = new NodeRuntimeContainersProbeParser()->parse(
            "orbit-container-scan:error Docker daemon unavailable\n",
        );

        expect($snapshot->status)
            ->toBe(NodeRuntimeContainersProbeStatus::Error)
            ->and($snapshot->containers->isEmpty())
            ->toBeTrue()
            ->and($snapshot->error)
            ->toBe('Docker daemon unavailable');
    });

    it('uses the unknown scan error when an error sentinel has no detail', function (): void {
        $snapshot = new NodeRuntimeContainersProbeParser()->parse("orbit-container-scan:error\n");

        expect($snapshot->status)
            ->toBe(NodeRuntimeContainersProbeStatus::Error)
            ->and($snapshot->error)
            ->toBe('unknown Docker runtime inventory failure');
    });

    it('reports a missing status sentinel', function (): void {
        $snapshot = new NodeRuntimeContainersProbeParser()->parse("orbit-app-docs\tdocs\n");

        expect($snapshot->status)
            ->toBe(NodeRuntimeContainersProbeStatus::Error)
            ->and($snapshot->containers->isEmpty())
            ->toBeTrue()
            ->and($snapshot->error)
            ->toBe('orbit-container-scan probe returned no status sentinel');
    });

    it('turns transport failures into an error snapshot', function (): void {
        $node = Node::factory()->appDev()->create();

        $snapshot = nodeRuntimeContainersInventory(
            new RuntimeException('Executor transport failed'),
        )
            ->introspect($node);

        expect($snapshot->status)
            ->toBe(NodeRuntimeContainersProbeStatus::Error)
            ->and($snapshot->containers->isEmpty())
            ->toBeTrue()
            ->and($snapshot->error)
            ->toBe('Executor transport failed');
    });
});

describe('node runtime container drift', function (): void {
    it('reports orphaned managed app runtime containers as process drift', function (): void {
        $node = Node::factory()->appDev()->create(['name' => 'app-1', 'tld' => 'app-one']);
        $snapshot = new NodeRuntimeContainersProbe(
            status: NodeRuntimeContainersProbeStatus::Present,
            containers: new ProbeSnapshot([
                'docs' => [
                    'container_name' => 'orbit-app-docs',
                    'app_slug' => 'docs',
                ],
                'removed' => [
                    'container_name' => 'orbit-app-removed',
                    'app_slug' => 'removed',
                ],
            ]),
        );

        $drift = nodeRuntimeContainersInventory("orbit-container-scan:present\n")
            ->diff($node, $snapshot, ['docs']);
        $extra = nodeRuntimeContainerIssue($drift, 'process.runtime_unit_extra');

        expect($extra)
            ->toBeInstanceOf(DriftEntry::class)
            ->and($extra?->family)
            ->toBe('process')
            ->and($extra?->kind)
            ->toBe(DriftKind::Extra)
            ->and($extra?->detail)
            ->toMatchArray([
                'runtime_unit' => 'orbit-app-removed',
                'app' => 'removed',
                'reason' => 'orphaned_managed_app_runtime',
            ]);
    });

    it('reports an unverifiable process backend when the node-wide scan fails', function (): void {
        $node = Node::factory()->appDev()->create(['name' => 'app-1', 'tld' => 'app-one']);
        $snapshot = new NodeRuntimeContainersProbe(
            status: NodeRuntimeContainersProbeStatus::Error,
            containers: new ProbeSnapshot([]),
            error: 'Docker daemon unavailable',
        );

        $drift = nodeRuntimeContainersInventory("orbit-container-scan:present\n")
            ->diff($node, $snapshot, []);
        $unavailable = nodeRuntimeContainerIssue($drift, 'process.runtime_backend_unavailable');

        expect($unavailable)
            ->toBeInstanceOf(DriftEntry::class)
            ->and($unavailable?->family)
            ->toBe('process')
            ->and($unavailable?->kind)
            ->toBe(DriftKind::Unverifiable)
            ->and($unavailable?->detail)
            ->toMatchArray([
                'backend' => 'docker',
                'reason' => 'runtime_inventory_probe_failed',
                'error' => 'Docker daemon unavailable',
            ]);
    });
});

final readonly class NodeRuntimeContainersInventoryExecutor implements RunsInternalCommands
{
    public function __construct(
        private string|Throwable $result,
    ) {}

    public function runInternal(
        Node $node,
        string $commandName,
        array $arguments = [],
        array $commandOptions = [],
        array $transportOptions = [],
    ): RemoteShellResult {
        if ($this->result instanceof Throwable) {
            throw $this->result;
        }

        return new RemoteShellResult(
            exitCode: 0,
            stdout: $this->result,
            stderr: '',
            durationMs: 1,
        );
    }
}

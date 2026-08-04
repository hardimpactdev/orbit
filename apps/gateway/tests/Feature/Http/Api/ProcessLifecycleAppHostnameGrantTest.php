<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const PROCESS_LIFECYCLE_HOSTNAME_GRANT_WG_IP = '10.6.0.97';

/**
 * Shared fixture for lifecycle POST grant authorization when process names collide
 * across nodes. Covers start/stop/restart under the shared AppOwning middleware path.
 *
 * @return array{
 *     caller: Node,
 *     grantedNode: Node,
 *     appHostname: string,
 *     workspaceHostname: string
 * }
 */
function processLifecycleHostnameGrantFixture(): array
{
    $caller = Node::factory()->create([
        'name' => 'lifecycle-caller',
        'host' => PROCESS_LIFECYCLE_HOSTNAME_GRANT_WG_IP,
        'wireguard_address' => PROCESS_LIFECYCLE_HOSTNAME_GRANT_WG_IP,
    ]);
    $grantedNode = createTestAppHostNode(['name' => 'granted-node']);
    $otherNode = createTestAppHostNode(['name' => 'other-node']);

    $grantedApp = Project::factory()->create(['name' => 'docs', 'node_id' => $grantedNode->id]);
    $otherApp = Project::factory()->create(['name' => 'other', 'node_id' => $otherNode->id]);
    $grantedInstance = AppInstance::factory()->create([
        'app_id' => $grantedApp->id,
        'name' => 'development',
        'driver_config' => new OrbitAppInstanceDriverConfigData(node_id: $grantedNode->id),
    ]);
    AppInstance::factory()->create([
        'app_id' => $otherApp->id,
        'name' => 'development',
        'driver_config' => new OrbitAppInstanceDriverConfigData(node_id: $otherNode->id),
    ]);
    $workspace = Workspace::factory()->create([
        'name' => 'feature-docs',
        'app_id' => $grantedApp->id,
        'app_instance_id' => $grantedInstance->id,
    ]);

    // Intentionally create the other-node process first so unscoped process-name
    // lookup would prefer the wrong serving node without app-hostname resolution.
    Process::factory()->forOwner($otherApp, $otherNode)->create(['name' => 'vite']);
    Process::factory()
        ->forOwner($grantedApp, $grantedNode)
        ->create([
            'app_instance_id' => $grantedInstance->id,
            'name' => 'vite',
        ]);

    ProxyRoute::factory()->create([
        'node_id' => $grantedNode->id,
        'domain' => 'docs-dev.example',
        'app_id' => $grantedApp->id,
        'owner_type' => 'app',
        'kind' => 'app',
        'config' => [
            'app_instance' => [
                'name' => 'development',
                'selector' => 'docs.development',
            ],
        ],
    ]);
    ProxyRoute::factory()->create([
        'node_id' => $grantedNode->id,
        'domain' => 'feature-docs.example',
        'app_id' => $grantedApp->id,
        'workspace_id' => $workspace->id,
        'owner_type' => 'workspace',
        'kind' => 'workspace',
    ]);

    return [
        'caller' => $caller,
        'grantedNode' => $grantedNode,
        'appHostname' => 'docs-dev.example',
        'workspaceHostname' => 'feature-docs.example',
    ];
}

function grantProcessLifecycleHostnameAccess(Node $caller, Node $servingNode, string $permission): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $servingNode->id,
        'permissions' => json_encode([$permission], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('process lifecycle app-hostname grant authorization', function (): void {
    it(
        'authorizes lifecycle POST against the app/workspace hostname node when process names collide',
        function (
            string $action,
            string $permission,
            string $hostKind,
            string $eventPath,
            string $eventType,
        ): void {
            $fixture = processLifecycleHostnameGrantFixture();
            grantProcessLifecycleHostnameAccess($fixture['caller'], $fixture['grantedNode'], $permission);

            $hostname = $hostKind === 'workspace'
                ? $fixture['workspaceHostname']
                : $fixture['appHostname'];

            app()->instance(RemoteShell::class, new ProcessLifecycleHostnameGrantRemoteShell([
                new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            ]));

            $response = $this->call(
                'POST',
                "/api/processes/{$action}",
                [
                    'app' => $hostname,
                    'name' => 'vite',
                ],
                [],
                [],
                ['REMOTE_ADDR' => PROCESS_LIFECYCLE_HOSTNAME_GRANT_WG_IP],
            );

            $response
                ->assertOk()
                ->assertJsonPath('success.data.runtimes.0.node', $fixture['grantedNode']->name)
                ->assertJsonPath($eventPath, $eventType);

            if ($hostKind === 'workspace') {
                $response->assertJsonPath('success.data.runtimes.0.workspace', 'feature-docs');
            } else {
                $response->assertJsonPath('success.data.runtimes.0.project', 'docs');
            }
        },
    )->with([
        'start app hostname' => [
            'start',
            'process:start',
            'app',
            'success.data.runtimes.0.event.type',
            'started',
        ],
        'stop app hostname' => [
            'stop',
            'process:stop',
            'app',
            'success.data.runtimes.0.event.type',
            'stopped',
        ],
        'restart app hostname' => [
            'restart',
            'process:restart',
            'app',
            'success.data.runtimes.0.events.0.type',
            'restarting',
        ],
        'start workspace hostname' => [
            'start',
            'process:start',
            'workspace',
            'success.data.runtimes.0.event.type',
            'started',
        ],
        'stop workspace hostname' => [
            'stop',
            'process:stop',
            'workspace',
            'success.data.runtimes.0.event.type',
            'stopped',
        ],
        'restart workspace hostname' => [
            'restart',
            'process:restart',
            'workspace',
            'success.data.runtimes.0.events.0.type',
            'restarting',
        ],
    ]);
});

final class ProcessLifecycleHostnameGrantRemoteShell implements RemoteShell
{
    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
        public array $scripts = [],
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

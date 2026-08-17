<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\ProxyRoute;
use App\Models\Workspace;
use App\Services\Processes\ProcessAppHostnameResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Orbit\Sdk\Laravel\GatewayApiException;

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

    $grantedApp = App::factory()->create(['name' => 'docs']);
    $otherApp = App::factory()->create(['name' => 'other']);
    $grantedInstance = Instance::factory()->create([
        'app_id' => $grantedApp->id,
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(node_id: $grantedNode->id),
    ]);
    Instance::factory()->create([
        'app_id' => $otherApp->id,
        'name' => 'development',
        'driver_config' => new OrbitInstanceDriverConfigData(node_id: $otherNode->id),
    ]);
    $workspace = Workspace::factory()->create([
        'name' => 'feature-docs',
        'app_id' => $grantedApp->id,
        'instance_id' => $grantedInstance->id,
    ]);

    // Intentionally create the other-node process first so unscoped process-name
    // lookup would prefer the wrong serving node without app-hostname resolution.
    Process::factory()->forOwner($otherApp, $otherNode)->create(['name' => 'vite']);
    Process::factory()
        ->forOwner($grantedApp, $grantedNode)
        ->create([
            'instance_id' => $grantedInstance->id,
            'name' => 'vite',
        ]);

    ProxyRoute::factory()->create([
        'node_id' => $grantedNode->id,
        'domain' => 'docs-dev.example',
        'app_id' => $grantedApp->id,
        'instance_id' => $grantedInstance->id,
        'owner_type' => 'app',
        'kind' => 'app',
        'config' => [
            'instance' => [
                'name' => 'development',
                'selector' => 'docs.development',
            ],
        ],
    ]);
    ProxyRoute::factory()->create([
        'node_id' => $grantedNode->id,
        'domain' => 'feature-docs.example',
        'app_id' => $grantedApp->id,
        'instance_id' => $grantedInstance->id,
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
                $response->assertJsonPath('success.data.runtimes.0.app', 'docs');
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

    it('rejects a workspace hostname whose workspace app disagrees with its concrete instance', function (): void {
        $fixture = processLifecycleHostnameGrantFixture();
        grantProcessLifecycleHostnameAccess(
            caller: $fixture['caller'],
            servingNode: $fixture['grantedNode'],
            permission: 'process:start',
        );
        $otherApp = App::factory()->create(['name' => 'other-owner']);
        $route = ProxyRoute::query()->where('domain', $fixture['workspaceHostname'])->firstOrFail();
        Workspace::query()->findOrFail($route->workspace_id)->forceFill(['app_id' => $otherApp->id])->save();
        $shell = new ProcessLifecycleHostnameGrantRemoteShell([]);
        app()->instance(RemoteShell::class, $shell);

        try {
            app(ProcessAppHostnameResolver::class)->resolve($fixture['workspaceHostname']);
            $this->fail('Expected invalid workspace route ownership to fail hostname resolution.');
        } catch (GatewayApiException $exception) {
            expect($exception->errorCode())
                ->toBe('validation_failed')
                ->and($exception->errorMeta())
                ->toMatchArray([
                    'field' => 'app',
                    'value' => $fixture['workspaceHostname'],
                    'reason' => 'instance_required',
                ]);
        }

        $response = $this->call(
            'POST',
            '/api/processes/start',
            [
                'app' => $fixture['workspaceHostname'],
                'name' => 'vite',
            ],
            [],
            [],
            ['REMOTE_ADDR' => PROCESS_LIFECYCLE_HOSTNAME_GRANT_WG_IP],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');

        expect($shell->scripts)->toBeEmpty();
    });
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

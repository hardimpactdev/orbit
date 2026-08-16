<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\Apps\OrbitInstanceDriverConfigData;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const APP_ROOT_CALLER_WG_IP = '10.6.0.79';

function createAppRootCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'host' => APP_ROOT_CALLER_WG_IP,
        'wireguard_address' => APP_ROOT_CALLER_WG_IP,
    ], $overrides));
}

/**
 * @param  list<string>  $permissions
 */
function grantAppRootAccess(Node $caller, Node $appNode, array $permissions = ['instance:root']): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode($permissions, JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('AppRootController', function (): void {
    it('updates app root for authorized callers', function (): void {
        Node::factory()->create([
            'name' => 'gateway-1',
        ]);

        $caller = createAppRootCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'tld' => 'test',
            'status' => 'active',
        ]);
        grantAppRootAccess($caller, $targetNode);

        $app = App::factory()->create([
            'name' => 'docs',
        ]);
        $instance = Instance::factory()->for($app)->create([
            'driver_config' => new OrbitInstanceDriverConfigData(
                node_id: $targetNode->id,
                path: '/home/orbit/apps/docs',
                document_root: 'public',
            ),
        ]);

        app()->instance(RemoteShell::class, new AppRootApiSequencedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm8.5', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call(
            'POST',
            '/api/instances/docs/root',
            [
                'root' => 'web',
            ],
            [],
            [],
            ['REMOTE_ADDR' => APP_ROOT_CALLER_WG_IP],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.app.name', 'docs')
            ->assertJsonPath('success.data.instance.root', 'web')
            ->assertJsonPath('success.data.result.changed', true)
            ->assertJsonPath('success.meta.node', 'app-1');

        expect($instance->refresh()->driver_config)
            ->toBeInstanceOf(OrbitInstanceDriverConfigData::class)
            ->and($instance->driver_config->document_root)
            ->toBe('web');
    });

    it('rejects root updates when the caller lacks instance:root on the app node', function (): void {
        Node::factory()->create([
            'name' => 'gateway-1',
        ]);

        $caller = createAppRootCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'status' => 'active',
        ]);
        grantAppRootAccess($caller, $targetNode, ['instance:read']);

        $app = App::factory()->create([
            'name' => 'docs',
        ]);
        $instance = Instance::factory()->for($app)->create([
            'driver_config' => new OrbitInstanceDriverConfigData(node_id: $targetNode->id),
        ]);

        app()->instance(RemoteShell::class, new AppRootApiSequencedRemoteShell([]));

        $response = $this->call(
            'POST',
            '/api/instances/docs/root',
            [
                'root' => 'web',
            ],
            [],
            [],
            ['REMOTE_ADDR' => APP_ROOT_CALLER_WG_IP],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'instance:root')
            ->assertJsonPath('error.meta.serving_node', 'app-1');

        expect($instance->refresh()->driver_config)
            ->toBeInstanceOf(OrbitInstanceDriverConfigData::class)
            ->and($instance->driver_config->document_root)
            ->not->toBe('web');
    });
});

final class AppRootApiSequencedRemoteShell implements RemoteShell
{
    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        return array_shift($this->results) ?? new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
    }
}

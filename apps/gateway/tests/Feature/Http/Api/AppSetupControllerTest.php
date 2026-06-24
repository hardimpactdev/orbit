<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\AppSetupRun;
use App\Models\AppSetupStep;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const APP_SETUP_CALLER_WG_IP = '10.6.0.97';

final class AppSetupControllerTestShell implements RemoteShell
{
    public array $runs = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->runs[] = compact('node', 'script', 'options');

        return new RemoteShellResult(
            exitCode: 0,
            stdout: 'ok',
            stderr: '',
            durationMs: 25,
        );
    }
}

function createAppSetupCallerNode(array $permissions = ['app:write']): Node
{
    $caller = Node::factory()->create([
        'name' => 'app-setup-caller',
        'host' => APP_SETUP_CALLER_WG_IP,
        'wireguard_address' => APP_SETUP_CALLER_WG_IP,
    ]);

    return $caller;
}

function grantAppSetupAccess(Node $caller, Node $appNode, array $permissions): void
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

function createAppSetupTarget(): array
{
    $node = Node::factory()
        ->appDev()
        ->create([
            'name' => 'app-1',
            'user' => 'orbit',
        ]);
    $app = App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'path' => '/home/orbit/apps/docs',
    ]);

    return [$node, $app];
}

describe('AppSetupController', function (): void {
    it('runs configured setup steps for authorized callers', function (): void {
        [$node, $app] = createAppSetupTarget();
        $caller = createAppSetupCallerNode();
        grantAppSetupAccess($caller, $node, ['app:write']);
        AppSetupStep::factory()->create([
            'app_id' => $app->id,
            'command' => 'npm install',
            'sort_order' => 1,
        ]);
        $shell = new AppSetupControllerTestShell;
        app()->instance(RemoteShell::class, $shell);

        $response = $this->call(
            'POST',
            '/api/apps/docs/setup',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => APP_SETUP_CALLER_WG_IP,
                'CONTENT_TYPE' => 'application/json',
            ],
        );

        $response
            ->assertOk()
            ->assertJsonPath('success.data.app', 'docs')
            ->assertJsonPath('success.data.setup_steps.status', 'completed')
            ->assertJsonPath('success.data.setup_steps.count', 1);

        expect($shell->runs)
            ->toHaveCount(1)
            ->and(AppSetupRun::query()->where('app_id', $app->id)->where('status', 'completed')->exists())
            ->toBeTrue();
    });

    it('rejects callers without app write permission', function (): void {
        [$node] = createAppSetupTarget();
        $caller = createAppSetupCallerNode();
        grantAppSetupAccess($caller, $node, ['app:read']);

        $response = $this->call(
            'POST',
            '/api/apps/docs/setup',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => APP_SETUP_CALLER_WG_IP,
                'CONTENT_TYPE' => 'application/json',
            ],
        );

        $response
            ->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.missing_permission', 'app:write');
    });
});

<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\OperationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\SiteCertificateInstallerFake;

uses(RefreshDatabase::class);

const APP_STORE_STREAM_CALLER_WG_IP = '10.6.0.177';

function createAppStoreStreamCallerNode(): Node
{
    return Node::factory()->create([
        'name' => 'stream-caller',
        'host' => APP_STORE_STREAM_CALLER_WG_IP,
        'wireguard_address' => APP_STORE_STREAM_CALLER_WG_IP,
    ]);
}

function assignAppStoreStreamRole(Node $node, string $role, array $settings = []): void
{
    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => $role,
        'status' => 'active',
        'settings' => $settings,
    ]);
}

function grantAppStoreStreamAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode(['app:new'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('streams app creation from an operation_run source', function (): void {
    $caller = createAppStoreStreamCallerNode();
    $targetNode = Node::factory()->create([
        'name' => 'app-1',
        'tld' => 'test',
        'status' => 'active',
    ]);
    assignAppStoreStreamRole($targetNode, 'app-dev', ['tld' => 'test']);
    grantAppStoreStreamAccess($caller, $targetNode);

    $remoteShell = new AppStoreStreamRecordingRemoteShell;
    app()->instance(RemoteShell::class, $remoteShell);
    app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);

    $response = $this->call(
        'POST',
        '/api/apps',
        [
            'name' => 'docs',
            'node' => 'app-1',
            'template_repository' => 'hardimpact/laravel-template',
            'new_repository' => 'hardimpact/docs',
            'root' => 'public',
            'php_version' => '8.5',
            'runtime_proxy_transport' => 'https',
        ],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'text/event-stream',
            'REMOTE_ADDR' => APP_STORE_STREAM_CALLER_WG_IP,
        ],
    );

    $response->assertOk();

    $content = $response->streamedContent();
    $operationRun = OperationRun::query()->where('operation_type', 'app:new')->firstOrFail();
    $sourceScript = collect($remoteShell->scripts)
        ->first(
            fn (string $script): bool => str_contains($script, 'internal:app-source:create'),
        );

    expect($content)
        ->toContain('event: tree')
        ->and($content)
        ->toContain('Creating App')
        ->and($content)
        ->toContain('Prepare app creation')
        ->and($content)
        ->toContain('Create project source')
        ->and($content)
        ->toContain('Register app')
        ->and($content)
        ->toContain('event: complete')
        ->and($content)
        ->toContain($operationRun->id)
        ->and($operationRun->status->value)
        ->toBe('succeeded')
        ->and($operationRun->caller_node_id)
        ->toBe($caller->id)
        ->and($operationRun->target_node_id)
        ->toBe($targetNode->id)
        ->and($operationRun->result['app']['name'])
        ->toBe('docs')
        ->and($operationRun->result['app']['runtime_config']['proxy_transport'])
        ->toBe('https')
        ->and($operationRun->result['app']['repository'])
        ->toBe('git@github.com:hardimpact/docs.git')
        ->and(App::query()->where('name', 'docs')->firstOrFail()->runtime_config)
        ->toBe(['proxy_transport' => 'https'])
        ->and(App::query()->where('name', 'docs')->firstOrFail()->repository)
        ->toBe('git@github.com:hardimpact/docs.git')
        ->and($sourceScript)
        ->toBeString()
        ->toContain("internal:app-source:create 'orbit' '/home/orbit/apps/docs'")
        ->toContain("--template-repository='hardimpact/laravel-template'")
        ->toContain("--new-repository='hardimpact/docs'")
        ->not->toContain('--repository=');
});

final class AppStoreStreamRecordingRemoteShell implements RemoteShell
{
    /** @var list<string> */
    public array $scripts = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 1,
        );
    }
}

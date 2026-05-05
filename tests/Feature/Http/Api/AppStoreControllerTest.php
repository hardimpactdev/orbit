<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const APP_STORE_CALLER_WG_IP = '10.6.0.77';

function createAppStoreCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'role' => 'control',
        'host' => APP_STORE_CALLER_WG_IP,
        'wireguard_address' => APP_STORE_CALLER_WG_IP,
    ], $overrides));
}

function grantAppStoreAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('AppStoreController', function (): void {
    it('creates app source and registry intent for authorized callers', function (): void {
        $caller = createAppStoreCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'role' => 'app',
            'tld' => 'test',
            'status' => 'active',
        ]);
        grantAppStoreAccess($caller, $targetNode);

        $remoteShell = new AppStoreRecordingRemoteShell;
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/apps', [
            'name' => 'docs',
            'node' => 'app-1',
            'root' => 'public',
            'php_version' => '8.5',
        ], [], [], ['REMOTE_ADDR' => APP_STORE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.result.action', 'created')
            ->assertJsonPath('success.data.app.name', 'docs')
            ->assertJsonPath('success.data.app.node', 'app-1')
            ->assertJsonPath('success.meta.warnings', []);

        expect(App::query()->where('name', 'docs')->exists())->toBeTrue()
            ->and($remoteShell->runs)->toHaveCount(3);
    });

    it('rejects app creation when the caller cannot access the target app node', function (): void {
        createAppStoreCallerNode();
        Node::factory()->create([
            'name' => 'app-1',
            'role' => 'app',
            'status' => 'active',
        ]);

        $remoteShell = new AppStoreRecordingRemoteShell;
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/apps', [
            'name' => 'docs',
            'node' => 'app-1',
        ], [], [], ['REMOTE_ADDR' => APP_STORE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');

        expect(App::query()->count())->toBe(0)
            ->and($remoteShell->runs)->toBe([]);
    });
});

final class AppStoreRecordingRemoteShell implements RemoteShell
{
    /**
     * @var list<array{node: int|null, script: string, options: array<string, mixed>}>
     */
    public array $runs = [];

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->runs[] = [
            'node' => $node->id,
            'script' => $script,
            'options' => $options,
        ];

        return new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 1,
        );
    }
}

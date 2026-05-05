<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const APP_REGISTER_CALLER_WG_IP = '10.6.0.78';

function createAppRegisterCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'role' => 'control',
        'host' => APP_REGISTER_CALLER_WG_IP,
        'wireguard_address' => APP_REGISTER_CALLER_WG_IP,
    ], $overrides));
}

function grantAppRegisterAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('AppRegisterController', function (): void {
    it('registers an existing app path for authorized callers', function (): void {
        Node::factory()->create([
            'name' => 'gateway-1',
            'role' => 'gateway',
            'is_local' => true,
        ]);

        $caller = createAppRegisterCallerNode();
        $targetNode = Node::factory()->create([
            'name' => 'app-1',
            'role' => 'app',
            'tld' => 'test',
            'status' => 'active',
        ]);
        grantAppRegisterAccess($caller, $targetNode);

        $remoteShell = new AppRegisterApiSequencedRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '/usr/sbin/php-fpm8.5', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/apps/register', [
            'name' => 'docs',
            'node' => 'app-1',
            'path' => '/home/orbit/apps/docs',
        ], [], [], ['REMOTE_ADDR' => APP_REGISTER_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.result.action', 'adopted')
            ->assertJsonPath('success.data.app.name', 'docs')
            ->assertJsonPath('success.data.app.node', 'app-1')
            ->assertJsonPath('success.meta.node', 'app-1')
            ->assertJsonPath('success.meta.warnings', []);

        expect(App::query()->where('name', 'docs')->exists())->toBeTrue()
            ->and($remoteShell->scripts[0])->toContain("test -d '/home/orbit/apps/docs'");
    });

    it('rejects registration when the caller cannot access the target app node', function (): void {
        Node::factory()->create([
            'name' => 'gateway-1',
            'role' => 'gateway',
            'is_local' => true,
        ]);

        createAppRegisterCallerNode();
        Node::factory()->create([
            'name' => 'app-1',
            'role' => 'app',
            'status' => 'active',
        ]);

        $remoteShell = new AppRegisterApiSequencedRemoteShell([]);
        app()->instance(RemoteShell::class, $remoteShell);

        $response = $this->call('POST', '/api/apps/register', [
            'name' => 'docs',
            'node' => 'app-1',
            'path' => '/home/orbit/apps/docs',
        ], [], [], ['REMOTE_ADDR' => APP_REGISTER_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');

        expect(App::query()->count())->toBe(0)
            ->and($remoteShell->scripts)->toBe([]);
    });
});

final class AppRegisterApiSequencedRemoteShell implements RemoteShell
{
    /**
     * @var list<string>
     */
    public array $scripts = [];

    /**
     * @param  list<RemoteShellResult>  $results
     */
    public function __construct(
        private array $results,
    ) {}

    public function run(Node $node, string $script, array $options = []): RemoteShellResult
    {
        $this->scripts[] = $script;

        return array_shift($this->results) ?? new RemoteShellResult(
            exitCode: 0,
            stdout: '',
            stderr: '',
            durationMs: 1,
        );
    }
}

<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\SiteCertificateInstallerFake;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app()->instance(SiteCertificateInstaller::class, new SiteCertificateInstallerFake);
});

const PROCESS_STORE_CALLER_WG_IP = '10.6.0.89';

function createProcessStoreCallerNode(array $overrides = []): Node
{
    return Node::factory()->create(array_merge([
        'name' => 'caller',
        'role' => 'control',
        'host' => PROCESS_STORE_CALLER_WG_IP,
        'wireguard_address' => PROCESS_STORE_CALLER_WG_IP,
    ], $overrides));
}

function grantProcessStoreAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('ProcessStoreController', function (): void {
    it('creates process intent for authorized control callers', function (): void {
        $caller = createProcessStoreCallerNode();
        $appNode = Node::factory()->create(['role' => 'app']);
        grantProcessStoreAccess($caller, $appNode);
        App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: 'supervisor OK', stderr: '', durationMs: 1),
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call('POST', '/api/processes', [
            'app' => 'docs',
            'name' => 'vite',
            'command' => 'npm run dev',
            'restart_policy' => 'on_failure',
            'crash_notification' => 'agent_ide',
        ], [], [], ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.process.name', 'vite')
            ->assertJsonPath('success.data.runtime_units.0.name', 'orbit_docs_main_vite')
            ->assertJsonPath('success.meta.warnings', []);

        expect(Process::query()->where('name', 'vite')->exists())->toBeTrue();
    });

    it('rejects unauthorized callers before writing intent', function (): void {
        createProcessStoreCallerNode();
        $appNode = Node::factory()->create(['role' => 'app']);
        App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([]));

        $response = $this->call('POST', '/api/processes', [
            'app' => 'docs',
            'name' => 'vite',
            'command' => 'npm run dev',
        ], [], [], ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed');

        expect(Process::query()->exists())->toBeFalse();
    });

    it('denies app callers before writing intent', function (): void {
        createProcessStoreCallerNode(['role' => 'app']);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([]));

        $response = $this->call('POST', '/api/processes', [
            'app' => 'docs',
            'name' => 'vite',
            'command' => 'npm run dev',
        ], [], [], ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'caller_role_not_allowed');
    });

    it('returns validation errors before writing intent', function (array $payload, string $field): void {
        createProcessStoreCallerNode(['role' => 'gateway']);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([]));

        $response = $this->call('POST', '/api/processes', $payload, [], [], ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.meta.field', $field);

        expect(Process::query()->exists())->toBeFalse();
    })->with([
        'missing app' => [['name' => 'vite', 'command' => 'npm run dev'], 'app'],
        'missing name' => [['app' => 'docs', 'command' => 'npm run dev'], 'name'],
        'missing command' => [['app' => 'docs', 'name' => 'vite'], 'command'],
        'invalid restart' => [['app' => 'docs', 'name' => 'vite', 'command' => 'npm run dev', 'restart_policy' => 'sometimes'], 'restart_policy'],
    ]);

    it('returns duplicate process conflicts', function (): void {
        createProcessStoreCallerNode(['role' => 'gateway']);
        $appNode = Node::factory()->create(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite']);
        app()->instance(RemoteShell::class, new ProcessStoreRemoteShell([]));

        $response = $this->call('POST', '/api/processes', [
            'app' => 'docs',
            'name' => 'vite',
            'command' => 'npm run dev',
        ], [], [], ['REMOTE_ADDR' => PROCESS_STORE_CALLER_WG_IP]);

        $response->assertStatus(409)
            ->assertJsonPath('error.code', 'process.name_collision');
    });
});

final class ProcessStoreRemoteShell implements RemoteShell
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

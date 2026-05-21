<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

const PROCESS_DESTROY_CALLER_WG_IP = '10.6.0.91';

function createProcessDestroyCallerNode(array $overrides = []): Node
{
    $attributes = array_merge([
        'name' => 'caller',
        'role' => 'control',
        'host' => PROCESS_DESTROY_CALLER_WG_IP,
        'wireguard_address' => PROCESS_DESTROY_CALLER_WG_IP,
    ], $overrides);

    return match ($attributes['role']) {
        'app' => createTestAppHostNode($attributes),
        'gateway' => createTestGatewayNode($attributes),
        default => Node::factory()->create($attributes),
    };
}

function grantProcessDestroyAccess(Node $caller, Node $appNode): void
{
    DB::table('node_access')->insert([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $appNode->id,
        'permissions' => json_encode(['process:remove'], JSON_THROW_ON_ERROR),
        'custom_permissions' => json_encode([], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('ProcessDestroyController', function (): void {
    it('removes process intent for authorized control callers with destructive consent', function (): void {
        $caller = createProcessDestroyCallerNode();
        $appNode = createTestAppHostNode(['role' => 'app']);
        grantProcessDestroyAccess($caller, $appNode);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite']);
        app()->instance(RemoteShell::class, new ProcessDestroyRemoteShell([
            new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1),
        ]));

        $response = $this->call('DELETE', '/api/processes/vite', [
            'app' => 'docs',
            'destructive_consent' => true,
        ], [], [], ['REMOTE_ADDR' => PROCESS_DESTROY_CALLER_WG_IP]);

        $response->assertOk()
            ->assertJsonPath('success.data.process', ['name' => 'vite', 'app' => 'docs'])
            ->assertJsonPath('success.data.removed_runtime_units', ['orbit_docs_main_vite'])
            ->assertJsonPath('success.meta.warnings', []);

        expect(Process::query()->where('name', 'vite')->exists())->toBeFalse();
    });

    it('requires authorization and destructive consent before deleting intent', function (array $payload, bool $grantAccess, int $status, string $code): void {
        $caller = createProcessDestroyCallerNode();
        $appNode = createTestAppHostNode(['role' => 'app']);
        if ($grantAccess) {
            grantProcessDestroyAccess($caller, $appNode);
        }
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite']);
        app()->instance(RemoteShell::class, new ProcessDestroyRemoteShell([]));

        $response = $this->call('DELETE', '/api/processes/vite', $payload, [], [], ['REMOTE_ADDR' => PROCESS_DESTROY_CALLER_WG_IP]);

        $response->assertStatus($status)
            ->assertJsonPath('error.code', $code);

        expect(Process::query()->where('name', 'vite')->exists())->toBeTrue();
    })->with([
        'missing consent' => [['app' => 'docs'], true, 422, 'validation_failed'],
        'unauthorized' => [['app' => 'docs', 'destructive_consent' => true], false, 403, 'authorization_failed'],
    ]);

    it('denies app callers without a process remove grant before deleting intent', function (): void {
        $caller = createProcessDestroyCallerNode(['role' => 'app']);
        $app = App::factory()->create(['name' => 'docs', 'node_id' => $caller->id]);
        Process::factory()->create(['app_id' => $app->id, 'name' => 'vite']);
        app()->instance(RemoteShell::class, new ProcessDestroyRemoteShell([]));

        $response = $this->call('DELETE', '/api/processes/vite', [
            'app' => 'docs',
            'destructive_consent' => true,
        ], [], [], ['REMOTE_ADDR' => PROCESS_DESTROY_CALLER_WG_IP]);

        $response->assertForbidden()
            ->assertJsonPath('error.code', 'authorization_failed')
            ->assertJsonPath('error.meta.reason', 'missing_permission')
            ->assertJsonPath('error.meta.missing_permission', 'process:remove');
    });

    it('returns process not found without cleanup', function (): void {
        createProcessDestroyCallerNode(['role' => 'gateway']);
        $appNode = createTestAppHostNode(['role' => 'app']);
        App::factory()->create(['name' => 'docs', 'node_id' => $appNode->id]);
        app()->instance(RemoteShell::class, new ProcessDestroyRemoteShell([]));

        $response = $this->call('DELETE', '/api/processes/vite', [
            'app' => 'docs',
            'destructive_consent' => true,
        ], [], [], ['REMOTE_ADDR' => PROCESS_DESTROY_CALLER_WG_IP]);

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'process.not_found');
    });
});

final class ProcessDestroyRemoteShell implements RemoteShell
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

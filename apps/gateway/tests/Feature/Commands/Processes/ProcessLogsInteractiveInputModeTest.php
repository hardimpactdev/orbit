<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\Process;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Prompts\DataTablePrompt;
use Laravel\Prompts\Key;

uses(RefreshDatabase::class);

function createProcessLogsInteractiveLocalNode(string $role = 'gateway'): Node
{
    $node = Node::factory()->create([
        'name' => "local-{$role}",
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);

    if ($role === 'gateway') {
        NodeRoleAssignment::factory()->create([
            'node_id' => $node->id,
            'role' => 'gateway',
            'status' => 'active',
            'settings' => [],
        ]);
    }

    return $node;
}

it('prompts for process name when name is absent', function (): void {
    createProcessLogsInteractiveLocalNode('gateway');
    $node = Node::factory()->appDev()->create();
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
    Process::factory()->create(['app_id' => $app->id, 'name' => 'web']);

    $remoteShell = new class implements RemoteShell
    {
        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
        }
    };
    app()->instance(RemoteShell::class, $remoteShell);

    DataTablePrompt::fake([Key::ENTER]);

    $this->artisan('process:logs --app=docs')
        ->assertSuccessful();
});

it('does not prompt when name is supplied', function (): void {
    createProcessLogsInteractiveLocalNode('gateway');
    $node = Node::factory()->appDev()->create();
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
    Process::factory()->create(['app_id' => $app->id, 'name' => 'web']);

    $remoteShell = new class implements RemoteShell
    {
        public function run(Node $node, string $script, array $options = []): RemoteShellResult
        {
            return new RemoteShellResult(exitCode: 0, stdout: '', stderr: '', durationMs: 1);
        }
    };
    app()->instance(RemoteShell::class, $remoteShell);

    $this->artisan('process:logs web --app=docs')
        ->doesntExpectOutput('Process name')
        ->assertSuccessful();
});

it('returns validation_failed in non-interactive mode when name is missing', function (): void {
    createProcessLogsInteractiveLocalNode('gateway');

    $exitCode = Artisan::call('process:logs', [
        '--app' => 'docs',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('name');
});

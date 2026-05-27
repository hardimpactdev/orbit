<?php

declare(strict_types=1);

use App\Contracts\RemoteShell;
use App\Data\RemoteShell\RemoteShellResult;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Prompts\DataTablePrompt;
use Laravel\Prompts\Key;

uses(RefreshDatabase::class);

function createProcessStopInteractiveLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
}

it('prompts for app when app is absent', function (): void {
    createProcessStopInteractiveLocalNode('gateway');
    $node = Node::factory()->create(['role' => 'app']);
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

    $this->artisan('process:stop')
        ->assertSuccessful();
});

it('does not prompt when --app is supplied', function (): void {
    createProcessStopInteractiveLocalNode('gateway');
    $node = Node::factory()->create(['role' => 'app']);
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

    $this->artisan('process:stop --app=docs')
        ->doesntExpectOutput('App')
        ->assertSuccessful();
});

it('returns validation_failed in non-interactive mode when app is missing', function (): void {
    createProcessStopInteractiveLocalNode('gateway');

    $exitCode = Artisan::call('process:stop', [
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('app');
});

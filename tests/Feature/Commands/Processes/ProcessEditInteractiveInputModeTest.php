<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

function createProcessEditInteractiveLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
}

it('prompts for app and name when both are absent', function (): void {
    createProcessEditInteractiveLocalNode('gateway');
    $node = Node::factory()->create(['role' => 'app', 'name' => 'app-1', 'user' => 'orbit']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/home/orbit/apps/docs']);
    Process::factory()->create(['app_id' => $app->id, 'name' => 'web']);

    $this->artisan('process:edit --command="php artisan serve"')
        ->expectsChoice('App', 'docs', ['docs'])
        ->expectsChoice('Process name', 'web', ['web'])
        ->assertSuccessful();
});

it('skips app prompt when --app is supplied', function (): void {
    createProcessEditInteractiveLocalNode('gateway');
    $node = Node::factory()->create(['role' => 'app', 'name' => 'app-1', 'user' => 'orbit']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/home/orbit/apps/docs']);
    Process::factory()->create(['app_id' => $app->id, 'name' => 'web']);

    $this->artisan('process:edit --app=docs --command="php artisan serve"')
        ->doesntExpectOutput('App')
        ->expectsChoice('Process name', 'web', ['web'])
        ->assertSuccessful();
});

it('does not prompt when name and app are both supplied', function (): void {
    createProcessEditInteractiveLocalNode('gateway');
    $node = Node::factory()->create(['role' => 'app', 'name' => 'app-1', 'user' => 'orbit']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id, 'path' => '/home/orbit/apps/docs']);
    Process::factory()->create(['app_id' => $app->id, 'name' => 'web']);

    $this->artisan('process:edit web --app=docs --command="php artisan serve"')
        ->doesntExpectOutput('App')
        ->doesntExpectOutput('Process name')
        ->assertSuccessful();
});

it('returns validation_failed in non-interactive mode when app is missing', function (): void {
    createProcessEditInteractiveLocalNode('gateway');

    $exitCode = Artisan::call('process:edit', [
        'name' => 'web',
        '--command' => 'php artisan serve',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('app');
});

it('returns validation_failed in non-interactive mode when name is missing', function (): void {
    createProcessEditInteractiveLocalNode('gateway');

    $exitCode = Artisan::call('process:edit', [
        '--app' => 'docs',
        '--command' => 'php artisan serve',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('name');
});

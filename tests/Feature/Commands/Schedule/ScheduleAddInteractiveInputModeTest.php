<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\SchedulerState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

function createScheduleAddInteractiveLocalNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
}

it('prompts for name, scope, execution source, and interval when all are absent', function (): void {
    createScheduleAddInteractiveLocalNode('gateway');
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
    SchedulerState::factory()->create(['node_id' => $node->id, 'heartbeat_at' => now()]);
    App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

    $this->artisan('schedule:add')
        ->expectsQuestion('Schedule name', 'my-schedule')
        ->expectsChoice('Scope', 'app', ['App', 'Node', 'app', 'node'])
        ->expectsQuestion('App name', 'docs')
        ->expectsChoice('Source', 'command', ['Inline command', 'Repo script', 'command', 'script'])
        ->expectsQuestion('Command', 'php artisan schedule:run')
        ->expectsQuestion('Interval (cron expression)', 'every minute')
        ->expectsOutputToContain("Schedule 'my-schedule' added.")
        ->assertExitCode(0);
});

it('skips name prompt when name argument is supplied', function (): void {
    createScheduleAddInteractiveLocalNode('gateway');
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
    SchedulerState::factory()->create(['node_id' => $node->id, 'heartbeat_at' => now()]);
    App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

    $this->artisan('schedule:add my-schedule')
        ->doesntExpectOutput('Schedule name')
        ->expectsChoice('Scope', 'app', ['App', 'Node', 'app', 'node'])
        ->expectsQuestion('App name', 'docs')
        ->expectsChoice('Source', 'command', ['Inline command', 'Repo script', 'command', 'script'])
        ->expectsQuestion('Command', 'php artisan schedule:run')
        ->expectsQuestion('Interval (cron expression)', 'every minute')
        ->assertExitCode(0);
});

it('skips target prompt when --app is supplied', function (): void {
    createScheduleAddInteractiveLocalNode('gateway');
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
    SchedulerState::factory()->create(['node_id' => $node->id, 'heartbeat_at' => now()]);
    App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

    $this->artisan('schedule:add my-schedule --app=docs')
        ->doesntExpectOutput('Scope')
        ->expectsChoice('Source', 'command', ['Inline command', 'Repo script', 'command', 'script'])
        ->expectsQuestion('Command', 'php artisan schedule:run')
        ->expectsQuestion('Interval (cron expression)', 'every minute')
        ->assertExitCode(0);
});

it('prompts for script path when script source is chosen', function (): void {
    createScheduleAddInteractiveLocalNode('gateway');
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
    SchedulerState::factory()->create(['node_id' => $node->id, 'heartbeat_at' => now()]);
    App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

    $this->artisan('schedule:add my-schedule --app=docs --interval=every\ minute')
        ->expectsChoice('Source', 'script', ['Inline command', 'Repo script', 'command', 'script'])
        ->expectsQuestion('Script path', '/opt/orbit/scripts/nightly.sh')
        ->assertExitCode(0);
});

it('skips all prompts when all options are supplied', function (): void {
    createScheduleAddInteractiveLocalNode('gateway');
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
    SchedulerState::factory()->create(['node_id' => $node->id, 'heartbeat_at' => now()]);
    App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);

    $this->artisan('schedule:add', [
        'name' => 'my-schedule',
        '--app' => 'docs',
        '--command' => 'php artisan schedule:run',
        '--interval' => 'every minute',
    ])
        ->doesntExpectOutput('Schedule name')
        ->doesntExpectOutput('Scope')
        ->doesntExpectOutput('Source')
        ->doesntExpectOutput('Interval')
        ->assertExitCode(0);
});

it('returns validation_failed in non-interactive mode when name is missing', function (): void {
    createScheduleAddInteractiveLocalNode('gateway');

    $exitCode = Artisan::call('schedule:add', [
        '--app' => 'docs',
        '--command' => 'php artisan schedule:run',
        '--interval' => 'every minute',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('name');
});

it('returns validation_failed in non-interactive mode when target is missing', function (): void {
    createScheduleAddInteractiveLocalNode('gateway');

    $exitCode = Artisan::call('schedule:add', [
        'name' => 'my-schedule',
        '--command' => 'php artisan schedule:run',
        '--interval' => 'every minute',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['fields'])->toBe(['app', 'node']);
});

it('returns validation_failed in non-interactive mode when execution source is missing', function (): void {
    createScheduleAddInteractiveLocalNode('gateway');

    $exitCode = Artisan::call('schedule:add', [
        'name' => 'my-schedule',
        '--app' => 'docs',
        '--interval' => 'every minute',
        '--json' => true,
    ]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['fields'])->toBe(['command', 'script']);
});

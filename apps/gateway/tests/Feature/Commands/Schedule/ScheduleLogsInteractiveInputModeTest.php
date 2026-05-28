<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\Schedule;
use App\Models\ScheduleRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Prompts\DataTablePrompt;
use Laravel\Prompts\Key;

uses(RefreshDatabase::class);

function createScheduleLogsInteractiveNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",

        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
}

it('prompts for name in interactive mode when name is missing', function (): void {
    createScheduleLogsInteractiveNode('gateway');
    $node = Node::factory()->create(['name' => 'app-1']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
    Schedule::factory()->forApp($app)->create([
        'name' => 'my-schedule',
        'schedule_key' => 'app:docs:my-schedule',
    ]);
    ScheduleRun::factory()->create([
        'node_id' => $node->id,
        'schedule_key' => 'app:docs:my-schedule',
        'status' => 'completed',
        'exit_code' => 0,
    ]);

    DataTablePrompt::fake([Key::ENTER]);

    $this->artisan('schedule:logs')
        ->assertSuccessful();
});

it('does not prompt when name argument is supplied in interactive mode', function (): void {
    createScheduleLogsInteractiveNode('gateway');
    $node = Node::factory()->create(['name' => 'app-1']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
    Schedule::factory()->forApp($app)->create([
        'name' => 'my-schedule',
        'schedule_key' => 'app:docs:my-schedule',
    ]);
    ScheduleRun::factory()->create([
        'node_id' => $node->id,
        'schedule_key' => 'app:docs:my-schedule',
        'status' => 'completed',
        'exit_code' => 0,
    ]);

    $this->artisan('schedule:logs', ['name' => 'my-schedule'])
        ->doesntExpectOutput('Schedule name')
        ->assertSuccessful();
});

it('returns validation_failed in non-interactive mode when name is missing', function (): void {
    createScheduleLogsInteractiveNode('gateway');

    $exitCode = Artisan::call('schedule:logs', ['--json' => true]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('name');
});

<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\Node;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Prompts\DataTablePrompt;
use Laravel\Prompts\Key;

uses(RefreshDatabase::class);

function createScheduleShowInteractiveNode(string $role = 'gateway'): Node
{
    return Node::factory()->create([
        'name' => "local-{$role}",
        'role' => $role,
        'host' => '10.6.0.1',
        'wireguard_address' => '10.6.0.1',
    ]);
}

it('prompts for name in interactive mode when name is missing', function (): void {
    createScheduleShowInteractiveNode('gateway');
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
    Schedule::factory()->forApp($app)->create(['name' => 'my-schedule']);

    DataTablePrompt::fake([Key::ENTER]);

    $this->artisan('schedule:show')
        ->expectsOutputToContain('my-schedule')
        ->assertSuccessful();
});

it('does not prompt when name argument is supplied in interactive mode', function (): void {
    createScheduleShowInteractiveNode('gateway');
    $node = Node::factory()->create(['name' => 'app-1', 'role' => 'app']);
    $app = App::factory()->create(['name' => 'docs', 'node_id' => $node->id]);
    Schedule::factory()->forApp($app)->create(['name' => 'my-schedule']);

    $this->artisan('schedule:show', ['name' => 'my-schedule'])
        ->doesntExpectOutput('Schedule name')
        ->assertSuccessful();
});

it('returns validation_failed in non-interactive mode when name is missing', function (): void {
    createScheduleShowInteractiveNode('gateway');

    $exitCode = Artisan::call('schedule:show', ['--json' => true]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('name');
});

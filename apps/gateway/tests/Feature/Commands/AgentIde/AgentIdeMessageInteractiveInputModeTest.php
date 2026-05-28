<?php

declare(strict_types=1);

use App\Contracts\AgentIdeMessageAdapter;
use App\Models\App;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Node::factory()->create([
        'name' => 'gateway-1',

    ]);

    $node = Node::factory()->create([
        'name' => 'app-1',

        'status' => 'active',
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    App::factory()->create([
        'name' => 'docs',
        'node_id' => $node->id,
        'agent_ide_config' => ['adapter' => 'opencode'],
    ]);

    $fake = new class implements AgentIdeMessageAdapter
    {
        public function activeSession(array $target, string $adapter): ?array
        {
            return ['id' => 'sess_123', 'status' => 'active'];
        }

        public function deliver(array $target, string $adapter, array $session, string $message): array
        {
            return ['status' => 'sent', 'session' => $session];
        }

        public function workspaces(array $target, string $adapter): array
        {
            return [];
        }
    };

    app()->instance(AgentIdeMessageAdapter::class, $fake);
    config(['orbit.is_gateway' => true]);
});

it('prompts for message in interactive mode when message is missing', function (): void {
    $this->artisan('agent-ide:message', ['--app' => 'docs'])
        ->expectsQuestion('Message', 'Hello from test')
        ->assertSuccessful();
});

it('does not prompt when message argument is supplied in interactive mode', function (): void {
    $this->artisan('agent-ide:message', ['message' => 'Hello', '--app' => 'docs'])
        ->doesntExpectOutput('Message')
        ->assertSuccessful();
});

it('returns validation_failed in non-interactive mode when message is missing', function (): void {
    $exitCode = Artisan::call('agent-ide:message', ['--app' => 'docs', '--json' => true]);
    $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(1)
        ->and($payload['error']['code'])->toBe('validation_failed')
        ->and($payload['error']['meta']['field'])->toBe('message');
});

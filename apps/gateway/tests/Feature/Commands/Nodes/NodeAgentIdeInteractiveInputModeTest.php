<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Prompts\DataTablePrompt;
use Laravel\Prompts\Key;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeAgentIdeInteractiveRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'role' => 'app',
        'host' => '10.6.0.7',
        'wireguard_address' => '10.6.0.7',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'environment' => 'development',
        'platform' => 'ubuntu_24-04',
        'agent_ide_config' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupNodeAgentIdeInteractiveGatewayCaller(): void
{
    config(['orbit.is_gateway' => true]);

    DB::table('nodes')->insert(nodeAgentIdeInteractiveRow([
        'name' => 'gateway-1',
        'role' => 'gateway',
        'environment' => null,
    ]));
}

describe('node:agent-ide interactive input mode', function (): void {
    it('prompts for missing node name and adapter in order', function (): void {
        setupNodeAgentIdeInteractiveGatewayCaller();
        DB::table('nodes')->insert(nodeAgentIdeInteractiveRow());

        DataTablePrompt::fake([Key::ENTER]);

        $this->artisan('node:agent-ide')
            ->expectsChoice('Agent IDE adapter', 'opencode', ['none', 'opencode', 'polyscope'])
            ->expectsOutputToContain("Node 'app-1' agent IDE set to 'opencode'")
            ->assertSuccessful();

        $config = json_decode((string) DB::table('nodes')->where('name', 'app-1')->value('agent_ide_config'), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($config)->toBe(['adapter' => 'opencode']);
    });

    it('prompts for the adapter when the node name is supplied', function (): void {
        setupNodeAgentIdeInteractiveGatewayCaller();
        DB::table('nodes')->insert(nodeAgentIdeInteractiveRow([
            'agent_ide_config' => json_encode(['adapter' => 'opencode'], JSON_THROW_ON_ERROR),
        ]));

        $this->artisan('node:agent-ide app-1')
            ->expectsChoice('Agent IDE adapter', 'none', ['none', 'opencode', 'polyscope'])
            ->expectsOutputToContain("Node 'app-1' agent IDE cleared")
            ->assertSuccessful();

        expect(DB::table('nodes')->where('name', 'app-1')->value('agent_ide_config'))->toBeNull();
    });

    it('does not prompt when json forces non-interactive mode', function (): void {
        setupNodeAgentIdeInteractiveGatewayCaller();
        DB::table('nodes')->insert(nodeAgentIdeInteractiveRow());

        $this->artisan('node:agent-ide --json')
            ->expectsOutputToContain('Node name is required.')
            ->assertFailed();
    });
});

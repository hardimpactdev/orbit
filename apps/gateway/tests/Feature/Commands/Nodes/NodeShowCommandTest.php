<?php

declare(strict_types=1);

use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeShowCommandRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'host' => '10.6.0.7',
        'wireguard_address' => '10.6.0.7',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'platform' => 'ubuntu_24-04',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setupNodeShowCommandGatewayCaller(): void
{
    config(['orbit.is_gateway' => true]);

    $nodeId = (int) DB::table('nodes')->insertGetId(nodeShowCommandRow([
        'name' => 'gateway-1',
    ]));

    NodeRoleAssignment::factory()->create([
        'node_id' => $nodeId,
        'role' => 'gateway',
        'status' => 'active',
    ]);
}

function setupNodeShowCommandControlCaller(): void
{
    DB::table('nodes')->insert(nodeShowCommandRow([
        'name' => 'control-1',
    ]));
}

function setupNodeShowCommandAppCaller(): void
{
    DB::table('nodes')->insert(nodeShowCommandRow([
        'name' => 'app-local',
    ]));
}

function setupNodeShowCommandUnknownCaller(): void
{
    DB::table('nodes')->insert(nodeShowCommandRow([
        'name' => 'weird',
    ]));
}

describe('node:show command contract', function (): void {
    beforeEach(function (): void {
        setupNodeShowCommandGatewayCaller();
    });

    it('renders a node show detail tree by name', function (): void {
        DB::table('nodes')->insert(nodeShowCommandRow());

        $exitCode = Artisan::call('node:show', ['name' => 'app-1']);
        $output = Artisan::output();

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('┌  Node: app-1')
            ->and($output)->toContain('├  Role')
            ->and($output)->toContain('├  OS')
            ->and($output)->toContain('└  Consuming')
            ->and($output)->not->toContain('+---');
    });

    it('defaults to the local node when no name is supplied', function (): void {
        DB::table('nodes')->insert(nodeShowCommandRow());

        $exitCode = Artisan::call('node:show', ['--json' => true]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['node']['name'])->toBe('gateway-1')
            ->and($payload['success']['data']['node'])->not->toHaveKey('role')
            ->and($payload['success']['data']['node']['roles'][0]['role'])->toBe('gateway');
    });

    it('ignores app nodes when resolving the gateway-local self target', function (): void {
        DB::table('nodes')->insert(nodeShowCommandRow());

        $exitCode = Artisan::call('node:show', ['--json' => true]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['node']['name'])->toBe('gateway-1');
    });

    it('does not mutate registry state or run processes', function (): void {
        DB::table('nodes')->insert(nodeShowCommandRow());

        Process::fake();
        Process::preventStrayProcesses();

        $before = DB::table('nodes')->where('name', 'app-1')->first();

        $this->artisan('node:show', ['name' => 'app-1'])->assertSuccessful();

        $after = DB::table('nodes')->where('name', 'app-1')->first();

        expect((array) $after)->toBe((array) $before);
        Process::assertRanTimes(fn (): bool => true, 0);
    });

    it('makes no DB writes during show', function (): void {
        DB::table('nodes')->insert(nodeShowCommandRow());

        $countBefore = DB::table('nodes')->count();

        $this->artisan('node:show', ['name' => 'app-1'])->assertSuccessful();

        expect(DB::table('nodes')->count())->toBe($countBefore);
    });

    it('makes no process calls during show', function (): void {
        Process::fake();
        Process::preventStrayProcesses();

        DB::table('nodes')->insert(nodeShowCommandRow());

        $this->artisan('node:show', ['name' => 'app-1'])->assertSuccessful();

        Process::assertNothingRan();
    });

    it('returns node.not_found for missing target', function (): void {
        $exitCode = Artisan::call('node:show', ['name' => 'nonexistent', '--json' => true]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.not_found');
    });
});

describe('node:show caller role behavior', function (): void {

    it('forwards for control callers and fails with gateway_unavailable', function (): void {
        config(['orbit.is_gateway' => false]);

        setupNodeShowCommandControlCaller();
        DB::table('nodes')->insert(nodeShowCommandRow());

        $exitCode = Artisan::call('node:show', ['name' => 'app-1', '--json' => true]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('gateway_unavailable');
    });

    it('returns real grant data for gateway-local lookups', function (): void {
        setupNodeShowCommandGatewayCaller();

        DB::table('nodes')->insert([
            nodeShowCommandRow([
                'name' => 'app-1',
            ]),
            nodeShowCommandRow([
                'name' => 'control-1',
            ]),
            nodeShowCommandRow([
                'name' => 'control-2',
            ]),
        ]);

        $app1Id = DB::table('nodes')->where('name', 'app-1')->value('id');
        $control1Id = DB::table('nodes')->where('name', 'control-1')->value('id');
        $control2Id = DB::table('nodes')->where('name', 'control-2')->value('id');

        DB::table('node_access')->insert([
            ['consumer_node_id' => $control1Id, 'serving_node_id' => $app1Id, 'created_at' => now(), 'updated_at' => now()],
            ['consumer_node_id' => $control2Id, 'serving_node_id' => $app1Id, 'created_at' => now(), 'updated_at' => now()],
            ['consumer_node_id' => $app1Id, 'serving_node_id' => $control1Id, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $exitCode = Artisan::call('node:show', ['name' => 'app-1', '--json' => true]);

        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['node']['grants']['consuming_nodes'])->toBe([
                ['name' => 'control-1', 'permissions' => ['*']],
                ['name' => 'control-2', 'permissions' => ['*']],
            ])
            ->and($payload['success']['data']['node']['grants']['serving_nodes'])->toBe([
                ['name' => 'control-1', 'permissions' => ['*']],
            ]);
    });
});

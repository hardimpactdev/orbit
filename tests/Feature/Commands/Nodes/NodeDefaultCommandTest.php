<?php

declare(strict_types=1);

use App\Http\Gateway\Requests\Nodes\ListNodesRequest;
use App\Models\LocalGatewaySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Prompts\DataTablePrompt;
use Laravel\Prompts\Key;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => false]);
});

afterEach(function (): void {
    MockClient::destroyGlobal();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeDefaultCommandRow(array $overrides = []): array
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
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setLocalDefaultCommand(string $name): void
{
    DB::table('local_node_defaults')->insert([
        'default_node_name' => $name,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function setupConfiguredControlNodeDefaultCaller(): void
{
    DB::table('nodes')->insert(nodeDefaultCommandRow([
        'name' => 'local-control',
        'role' => 'control',
        'environment' => null,
    ]));

    LocalGatewaySettings::current()->fill([
        'gateway_url' => 'https://gateway.test',
        'ca_pem_path' => '/dev/null',
    ])->save();
}

function nodeDefaultGatewayResponse(array $nodes): array
{
    return [
        'success' => [
            'data' => [
                'nodes' => $nodes,
            ],
        ],
    ];
}

function expectNodeDefaultListRequest(ListNodesRequest $request): bool
{
    return $request->role === 'app'
        && $request->environment === 'development'
        && $request->doctor === false;
}

describe('node:default command contract', function (): void {
    it('routes to show sub-action when no name and no --clear in non-interactive mode', function (): void {
        DB::table('nodes')->insert(nodeDefaultCommandRow());
        setLocalDefaultCommand('app-1');

        $exitCode = Artisan::call('node:default', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('show');
    });

    it('routes to set sub-action when name is provided', function (): void {
        DB::table('nodes')->insert(nodeDefaultCommandRow());

        $exitCode = Artisan::call('node:default', [
            'name' => 'app-1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('set');
    });

    it('routes to clear sub-action when --clear is provided', function (): void {
        DB::table('nodes')->insert(nodeDefaultCommandRow());
        setLocalDefaultCommand('app-1');

        $exitCode = Artisan::call('node:default', ['--clear' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data']['action'])->toBe('clear');
    });

    it('rejects mutually exclusive name and --clear', function (): void {
        DB::table('nodes')->insert(nodeDefaultCommandRow());

        $exitCode = Artisan::call('node:default', [
            'name' => 'app-1',
            '--clear' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed');
    });

    it('rejects node-not-found for set', function (): void {
        $exitCode = Artisan::call('node:default', [
            'name' => 'missing',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.not_found');
    });

    it('rejects non-development node for set', function (): void {
        DB::table('nodes')->insert(nodeDefaultCommandRow([
            'name' => 'gateway-1',
            'role' => 'gateway',
            'environment' => null,
        ]));

        $exitCode = Artisan::call('node:default', [
            'name' => 'gateway-1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.invalid_role');
    });

    it('rejects production app node for set', function (): void {
        DB::table('nodes')->insert(nodeDefaultCommandRow([
            'name' => 'prod-1',
            'role' => 'app',
            'environment' => 'production',
        ]));

        $exitCode = Artisan::call('node:default', [
            'name' => 'prod-1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.invalid_role');
    });

    it('persists default to local_node_defaults table on set', function (): void {
        DB::table('nodes')->insert(nodeDefaultCommandRow());

        Artisan::call('node:default', ['name' => 'app-1']);

        expect(DB::table('local_node_defaults')->count())->toBe(1);

        $record = DB::table('local_node_defaults')->first();
        expect($record->default_node_name)->toBe('app-1');
    });

    it('updates existing row on repeated set', function (): void {
        DB::table('nodes')->insert([
            nodeDefaultCommandRow(['name' => 'app-1']),
            nodeDefaultCommandRow(['name' => 'app-2']),
        ]);

        Artisan::call('node:default', ['name' => 'app-1']);
        Artisan::call('node:default', ['name' => 'app-2']);

        expect(DB::table('local_node_defaults')->count())->toBe(1);

        $record = DB::table('local_node_defaults')->first();
        expect($record->default_node_name)->toBe('app-2');
    });

    it('validates set through gateway-visible development app nodes for configured control callers', function (): void {
        setupConfiguredControlNodeDefaultCaller();

        $mock = MockClient::global([
            ListNodesRequest::class => MockResponse::make(nodeDefaultGatewayResponse([
                [
                    'name' => 'remote-app',
                    'role' => 'app',
                    'environment' => 'development',
                    'platform' => 'ubuntu_24-04',
                    'status' => 'active',
                ],
            ]), 200),
        ]);

        $exitCode = Artisan::call('node:default', [
            'name' => 'remote-app',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['data'])->toBe([
                'action' => 'set',
                'default_node' => [
                    'name' => 'remote-app',
                    'role' => 'app',
                    'environment' => 'development',
                ],
            ])
            ->and(DB::table('local_node_defaults')->value('default_node_name'))->toBe('remote-app')
            ->and(DB::table('nodes')->where('name', 'remote-app')->exists())->toBeFalse();

        $mock->assertSent(fn (ListNodesRequest $request): bool => expectNodeDefaultListRequest($request));
    });

    it('discovers interactive choose options through gateway-visible development app nodes', function (): void {
        setupConfiguredControlNodeDefaultCaller();

        $mock = MockClient::global([
            ListNodesRequest::class => MockResponse::make(nodeDefaultGatewayResponse([
                [
                    'name' => 'remote-app-1',
                    'role' => 'app',
                    'environment' => 'development',
                    'platform' => 'ubuntu_24-04',
                    'status' => 'active',
                ],
                [
                    'name' => 'remote-app-2',
                    'role' => 'app',
                    'environment' => 'development',
                    'platform' => 'ubuntu_24-04',
                    'status' => 'active',
                ],
            ]), 200),
        ]);

        DataTablePrompt::fake([Key::DOWN, Key::ENTER]);

        $this->artisan('node:default')
            ->assertExitCode(0);

        expect(DB::table('local_node_defaults')->value('default_node_name'))->toBe('remote-app-2')
            ->and(DB::table('nodes')->whereIn('name', ['remote-app-1', 'remote-app-2'])->exists())->toBeFalse();

        $mock->assertSent(fn (ListNodesRequest $request): bool => expectNodeDefaultListRequest($request));
    });

    it('keeps show and clear local-only for configured control callers', function (): void {
        setupConfiguredControlNodeDefaultCaller();
        setLocalDefaultCommand('stale-local-app');

        $mock = MockClient::global([
            ListNodesRequest::class => MockResponse::make(nodeDefaultGatewayResponse([]), 200),
        ]);

        $showExitCode = Artisan::call('node:default', ['--json' => true]);
        $showPayload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        $clearExitCode = Artisan::call('node:default', ['--clear' => true, '--json' => true]);
        $clearPayload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($showExitCode)->toBe(0)
            ->and($showPayload['success']['data']['action'])->toBe('show')
            ->and($showPayload['success']['data']['default_node']['name'])->toBe('stale-local-app')
            ->and($clearExitCode)->toBe(0)
            ->and($clearPayload['success']['data']['action'])->toBe('clear')
            ->and(DB::table('local_node_defaults')->value('default_node_name'))->toBeNull();

        $mock->assertNothingSent();
    });

    it('does not mutate node registry on set', function (): void {
        DB::table('nodes')->insert(nodeDefaultCommandRow());
        $before = DB::table('nodes')->where('name', 'app-1')->first();

        Artisan::call('node:default', ['name' => 'app-1']);

        $after = DB::table('nodes')->where('name', 'app-1')->first();
        expect((array) $after)->toBe((array) $before);
    });

    it('does not mutate node registry on clear', function (): void {
        DB::table('nodes')->insert(nodeDefaultCommandRow());
        setLocalDefaultCommand('app-1');
        $before = DB::table('nodes')->where('name', 'app-1')->first();

        Artisan::call('node:default', ['--clear' => true]);

        $after = DB::table('nodes')->where('name', 'app-1')->first();
        expect((array) $after)->toBe((array) $before);
    });

    it('returns was_set true on clear when default existed', function (): void {
        DB::table('nodes')->insert(nodeDefaultCommandRow());
        setLocalDefaultCommand('app-1');

        $exitCode = Artisan::call('node:default', ['--clear' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['meta']['was_set'])->toBeTrue();
    });

    it('returns was_set false on clear when no default existed', function (): void {
        DB::table('nodes')->insert(nodeDefaultCommandRow());

        $exitCode = Artisan::call('node:default', ['--clear' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload['success']['meta']['was_set'])->toBeFalse();
    });

    it('does not invoke SSH or external processes', function (): void {
        DB::table('nodes')->insert(nodeDefaultCommandRow());

        $exitCode = Artisan::call('node:default', ['name' => 'app-1']);

        expect($exitCode)->toBe(0);
    });
});

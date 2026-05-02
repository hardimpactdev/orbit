<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function nodeDefaultRow(array $overrides = []): array
{
    return array_merge([
        'name' => 'app-1',
        'role' => 'app',
        'host' => '10.6.0.7',
        'wireguard_address' => '10.6.0.7',
        'ssh_user' => 'nckrtl',
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
        'is_local' => false,
        'environment' => 'development',
        'platform' => 'ubuntu_24-04',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

function setLocalDefault(string $name): void
{
    DB::table('local_node_defaults')->insert([
        'default_node_name' => $name,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

describe('node:default show sub-action', function (): void {
    it('shows the current default in human format', function (): void {
        DB::table('nodes')->insert(nodeDefaultRow());
        setLocalDefault('app-1');

        $this->artisan('node:default')
            ->expectsOutputToContain('Default development app node: app-1')
            ->assertSuccessful();
    });

    it('shows empty state in human format when no default is set', function (): void {
        DB::table('nodes')->insert(nodeDefaultRow());

        $this->artisan('node:default')
            ->expectsOutputToContain('No default development app node is set.')
            ->assertSuccessful();
    });

    it('returns JSON success with default_node for show', function (): void {
        DB::table('nodes')->insert(nodeDefaultRow());
        setLocalDefault('app-1');

        $exitCode = Artisan::call('node:default', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toBe([
                'success' => [
                    'data' => [
                        'action' => 'show',
                        'default_node' => [
                            'name' => 'app-1',
                            'role' => 'app',
                            'environment' => 'development',
                        ],
                    ],
                ],
            ]);
    });

    it('returns JSON success with null default_node for empty show', function (): void {
        DB::table('nodes')->insert(nodeDefaultRow());

        $exitCode = Artisan::call('node:default', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toBe([
                'success' => [
                    'data' => [
                        'action' => 'show',
                        'default_node' => null,
                    ],
                ],
            ]);
    });

    it('omits meta from JSON success for show', function (): void {
        DB::table('nodes')->insert(nodeDefaultRow());
        setLocalDefault('app-1');

        Artisan::call('node:default', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['success'])->not->toHaveKey('meta');
    });
});

describe('node:default set sub-action', function (): void {
    beforeEach(function (): void {
        DB::table('nodes')->insert([
            nodeDefaultRow(['name' => 'app-1', 'role' => 'app', 'environment' => 'development']),
            nodeDefaultRow(['name' => 'gateway-1', 'role' => 'gateway', 'environment' => null]),
            nodeDefaultRow(['name' => 'prod-1', 'role' => 'app', 'environment' => 'production']),
        ]);
    });

    it('sets a valid development app node as default in human format', function (): void {
        $this->artisan('node:default', ['name' => 'app-1'])
            ->expectsOutputToContain('Default development app node set to app-1.')
            ->assertSuccessful();

        $record = DB::table('local_node_defaults')->first();
        expect($record->default_node_name)->toBe('app-1');
    });

    it('returns JSON success for set with default_node', function (): void {
        $exitCode = Artisan::call('node:default', [
            'name' => 'app-1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toBe([
                'success' => [
                    'data' => [
                        'action' => 'set',
                        'default_node' => [
                            'name' => 'app-1',
                            'role' => 'app',
                            'environment' => 'development',
                        ],
                    ],
                ],
            ]);
    });

    it('omits meta from JSON success for set', function (): void {
        Artisan::call('node:default', ['name' => 'app-1', '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['success'])->not->toHaveKey('meta');
    });

    it('rejects set with non-existent node in JSON', function (): void {
        $exitCode = Artisan::call('node:default', [
            'name' => 'missing',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toBe([
                'error' => [
                    'code' => 'node.not_found',
                    'message' => "Node 'missing' not found or not visible.",
                    'meta' => [
                        'name' => 'missing',
                    ],
                ],
            ]);
    });

    it('rejects set with non-existent node in human format', function (): void {
        $this->artisan('node:default', ['name' => 'missing'])
            ->expectsOutputToContain("Node 'missing' not found or not visible.")
            ->assertFailed();
    });

    it('rejects set with gateway node in JSON', function (): void {
        $exitCode = Artisan::call('node:default', [
            'name' => 'gateway-1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toBe([
                'error' => [
                    'code' => 'node.invalid_role',
                    'message' => "Node 'gateway-1' is not a development app node.",
                    'meta' => [
                        'name' => 'gateway-1',
                        'role' => 'gateway',
                        'required_role' => 'app',
                        'required_environment' => 'development',
                    ],
                ],
            ]);
    });

    it('rejects set with production app node in JSON', function (): void {
        $exitCode = Artisan::call('node:default', [
            'name' => 'prod-1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('node.invalid_role');
    });
});

describe('node:default clear sub-action', function (): void {
    it('clears an existing default and returns was_set true in JSON', function (): void {
        setLocalDefault('app-1');

        $exitCode = Artisan::call('node:default', ['--clear' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toBe([
                'success' => [
                    'data' => [
                        'action' => 'clear',
                        'default_node' => null,
                    ],
                    'meta' => [
                        'was_set' => true,
                    ],
                ],
            ]);

        $record = DB::table('local_node_defaults')->first();
        expect($record->default_node_name)->toBeNull();
    });

    it('clears with no existing default and returns was_set false in JSON', function (): void {
        $exitCode = Artisan::call('node:default', ['--clear' => true, '--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(0)
            ->and($payload)->toBe([
                'success' => [
                    'data' => [
                        'action' => 'clear',
                        'default_node' => null,
                    ],
                    'meta' => [
                        'was_set' => false,
                    ],
                ],
            ]);
    });

    it('shows human confirmation when clearing existing default', function (): void {
        setLocalDefault('app-1');

        $this->artisan('node:default', ['--clear' => true])
            ->expectsOutputToContain('Default development app node cleared.')
            ->assertSuccessful();
    });

    it('shows human no-op when clearing without existing default', function (): void {
        $this->artisan('node:default', ['--clear' => true])
            ->expectsOutputToContain('No default development app node was set.')
            ->assertSuccessful();
    });
});

describe('node:default validation failures', function (): void {
    it('rejects mutually exclusive name and --clear in JSON', function (): void {
        $exitCode = Artisan::call('node:default', [
            'name' => 'app-1',
            '--clear' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toBe([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Provide only one node target.',
                    'meta' => [
                        'fields' => ['name', 'clear'],
                    ],
                ],
            ]);
    });

    it('rejects mutually exclusive name and --clear in human format', function (): void {
        $this->artisan('node:default', ['name' => 'app-1', '--clear' => true])
            ->expectsOutputToContain('Provide only one node target.')
            ->assertFailed();
    });

    it('rejects empty string name in JSON', function (): void {
        $exitCode = Artisan::call('node:default', [
            'name' => '',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toBe([
                'error' => [
                    'code' => 'validation_failed',
                    'message' => 'Node name cannot be empty.',
                    'meta' => [
                        'field' => 'name',
                    ],
                ],
            ]);
    });

    it('rejects empty string name in human format', function (): void {
        $this->artisan('node:default', ['name' => ''])
            ->expectsOutputToContain('Node name cannot be empty.')
            ->assertFailed();
    });
});

describe('node:default caller role rejections', function (): void {
    it('rejects app-node callers in JSON', function (): void {
        DB::table('nodes')->insert(nodeDefaultRow([
            'name' => 'mini',
            'role' => 'app',
            'is_local' => true,
            'environment' => 'development',
        ]));

        $exitCode = Artisan::call('node:default', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toBe([
                'error' => [
                    'code' => 'caller_role_not_allowed',
                    'message' => 'This command may only be run from a control node.',
                    'meta' => [
                        'caller_role' => 'app',
                    ],
                ],
            ]);
    });

    it('rejects app-node callers in human format', function (): void {
        DB::table('nodes')->insert(nodeDefaultRow([
            'name' => 'mini',
            'role' => 'app',
            'is_local' => true,
            'environment' => 'development',
        ]));

        $this->artisan('node:default')
            ->expectsOutputToContain('This command may only be run from a control node.')
            ->assertFailed();
    });

    it('rejects gateway callers in JSON', function (): void {
        DB::table('nodes')->insert(nodeDefaultRow([
            'name' => 'gateway-1',
            'role' => 'gateway',
            'is_local' => true,
            'environment' => null,
        ]));

        $exitCode = Artisan::call('node:default', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('caller_role_not_allowed')
            ->and($payload['error']['meta']['caller_role'])->toBe('gateway');
    });

    it('rejects unknown caller role in JSON', function (): void {
        DB::table('nodes')->insert(nodeDefaultRow([
            'name' => 'weird',
            'role' => 'bogus',
            'is_local' => true,
            'environment' => null,
        ]));

        $exitCode = Artisan::call('node:default', ['--json' => true]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload)->toBe([
                'error' => [
                    'code' => 'local_context_invalid',
                    'message' => 'Local node role setting is invalid.',
                    'meta' => [
                        'setting' => 'general.local_node_role',
                        'reason' => 'unsupported_value',
                        'caller_role' => 'unknown',
                    ],
                ],
            ]);
    });
});

describe('node:default local write guarantee', function (): void {
    it('persists default to local_node_defaults table on set', function (): void {
        DB::table('nodes')->insert(nodeDefaultRow());

        $this->artisan('node:default', ['name' => 'app-1'])
            ->assertSuccessful();

        expect(DB::table('local_node_defaults')->count())->toBe(1);

        $record = DB::table('local_node_defaults')->first();
        expect($record->default_node_name)->toBe('app-1');
    });

    it('updates existing row on repeated set', function (): void {
        DB::table('nodes')->insert([
            nodeDefaultRow(['name' => 'app-1']),
            nodeDefaultRow(['name' => 'app-2']),
        ]);

        $this->artisan('node:default', ['name' => 'app-1'])->assertSuccessful();
        $this->artisan('node:default', ['name' => 'app-2'])->assertSuccessful();

        expect(DB::table('local_node_defaults')->count())->toBe(1);

        $record = DB::table('local_node_defaults')->first();
        expect($record->default_node_name)->toBe('app-2');
    });

    it('does not mutate node registry on set', function (): void {
        DB::table('nodes')->insert(nodeDefaultRow());
        $before = DB::table('nodes')->where('name', 'app-1')->first();

        $this->artisan('node:default', ['name' => 'app-1'])->assertSuccessful();

        $after = DB::table('nodes')->where('name', 'app-1')->first();
        expect((array) $after)->toBe((array) $before);
    });

    it('does not mutate node registry on clear', function (): void {
        DB::table('nodes')->insert(nodeDefaultRow());
        setLocalDefault('app-1');
        $before = DB::table('nodes')->where('name', 'app-1')->first();

        $this->artisan('node:default', ['--clear' => true])->assertSuccessful();

        $after = DB::table('nodes')->where('name', 'app-1')->first();
        expect((array) $after)->toBe((array) $before);
    });
});

describe('node:default progress tree', function (): void {
    it('renders progress tree for set sub-action in human format', function (): void {
        DB::table('nodes')->insert(nodeDefaultRow());

        $this->artisan('node:default', ['name' => 'app-1'])
            ->expectsOutputToContain('┌ Set Default Node')
            ->expectsOutputToContain('○ Load visible development app nodes')
            ->expectsOutputToContain('○ Store local default')
            ->expectsOutputToContain('└ Default development app node set to app-1.')
            ->assertSuccessful();
    });
});

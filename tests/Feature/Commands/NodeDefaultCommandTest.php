<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['orbit.is_gateway' => false]);
});

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
        'user' => 'nckrtl',
        'orbit_path' => '/home/nckrtl/orbit',
        'status' => 'active',
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

describe('node:default base invocation', function (): void {
    it('invokes show sub-action successfully', function (): void {
        DB::table('nodes')->insert(nodeDefaultRow());
        setLocalDefault('app-1');

        $this->artisan('node:default', ['--no-interaction' => true])
            ->assertSuccessful();
    });

    it('invokes set sub-action successfully', function (): void {
        DB::table('nodes')->insert(nodeDefaultRow());

        $this->artisan('node:default', ['name' => 'app-1'])
            ->assertSuccessful();
    });

    it('invokes clear sub-action successfully', function (): void {
        DB::table('nodes')->insert(nodeDefaultRow());
        setLocalDefault('app-1');

        $this->artisan('node:default', ['--clear' => true])
            ->assertSuccessful();
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

describe('node:default validation edge cases', function (): void {
    it('rejects empty string name', function (): void {
        $exitCode = Artisan::call('node:default', [
            'name' => '',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($exitCode)->toBe(1)
            ->and($payload['error']['code'])->toBe('validation_failed')
            ->and($payload['error']['message'])->toBe('Node name cannot be empty.');
    });
});

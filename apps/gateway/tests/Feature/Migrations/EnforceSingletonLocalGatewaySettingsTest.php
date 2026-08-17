<?php

declare(strict_types=1);

use App\Models\LocalGatewaySettings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function enforce_singleton_local_gateway_settings_migration_path(): string
{
    return database_path('migrations/2026_08_17_120000_enforce_singleton_local_gateway_settings.php');
}

function restore_pre_singleton_local_gateway_settings_schema(): void
{
    if (! Schema::hasTable('local_gateway_settings')) {
        return;
    }

    if (Schema::hasIndex('local_gateway_settings', ['singleton_key'], 'unique')) {
        Schema::table('local_gateway_settings', static function (Blueprint $table): void {
            $table->dropUnique('local_gateway_settings_singleton_key_unique');
        });
    }

    if (Schema::hasColumn('local_gateway_settings', 'singleton_key')) {
        Schema::table('local_gateway_settings', static function (Blueprint $table): void {
            $table->dropColumn('singleton_key');
        });
    }

    DB::table('local_gateway_settings')->delete();
}

function run_enforce_singleton_local_gateway_settings_migration(): void
{
    $migrationPath = enforce_singleton_local_gateway_settings_migration_path();

    expect($migrationPath)->toBeFile();

    $migration = require $migrationPath;
    $migration->up();
}

it('is a no-op when the table has zero rows', function (): void {
    restore_pre_singleton_local_gateway_settings_schema();

    run_enforce_singleton_local_gateway_settings_migration();

    expect(LocalGatewaySettings::query()->count())
        ->toBe(0)
        ->and(Schema::hasColumn('local_gateway_settings', 'singleton_key'))
        ->toBeTrue()
        ->and(Schema::hasIndex('local_gateway_settings', ['singleton_key'], 'unique'))
        ->toBeTrue();
});

it('leaves a single existing row in place and stamps the singleton key', function (): void {
    restore_pre_singleton_local_gateway_settings_schema();

    $now = now();
    $id = DB::table('local_gateway_settings')->insertGetId([
        'gateway_url' => 'https://10.6.0.2',
        'gateway_wg_ip' => '10.6.0.2',
        'ca_sha256' => 'aabbccdd',
        'ca_pem_path' => '/path/to/ca.pem',
        'trusted_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    run_enforce_singleton_local_gateway_settings_migration();

    $row = DB::table('local_gateway_settings')->sole();

    expect($row->id)
        ->toBe($id)
        ->and($row->gateway_url)
        ->toBe('https://10.6.0.2')
        ->and($row->gateway_wg_ip)
        ->toBe('10.6.0.2')
        ->and($row->ca_sha256)
        ->toBe('aabbccdd')
        ->and($row->ca_pem_path)
        ->toBe('/path/to/ca.pem')
        ->and($row->singleton_key)
        ->toBe(LocalGatewaySettings::SINGLETON_KEY);
});

it('consolidates duplicates onto the lowest-id row using newest created_at then highest id', function (): void {
    restore_pre_singleton_local_gateway_settings_schema();

    $older = now()->subDay();
    $newer = now();

    $canonicalId = DB::table('local_gateway_settings')->insertGetId([
        'gateway_url' => 'https://old.example',
        'gateway_wg_ip' => '10.6.0.99',
        'ca_sha256' => 'old-sha',
        'ca_pem_path' => '/tmp/old.pem',
        'trusted_at' => $older,
        'created_at' => $older,
        'updated_at' => $older,
    ]);
    DB::table('local_gateway_settings')->insert([
        'gateway_url' => 'https://mid.example',
        'gateway_wg_ip' => '10.6.0.50',
        'ca_sha256' => 'mid-sha',
        'ca_pem_path' => '/tmp/mid.pem',
        'trusted_at' => $newer,
        'created_at' => $newer,
        'updated_at' => $newer,
    ]);
    DB::table('local_gateway_settings')->insert([
        'gateway_url' => 'https://10.6.0.1',
        'gateway_wg_ip' => '10.6.0.1',
        'ca_sha256' => 'deadbeef',
        'ca_pem_path' => '/tmp/ca.pem',
        'trusted_at' => $newer,
        'created_at' => $newer,
        'updated_at' => $newer,
    ]);

    run_enforce_singleton_local_gateway_settings_migration();

    $row = DB::table('local_gateway_settings')->sole();

    expect($row->id)
        ->toBe($canonicalId)
        ->and($row->gateway_url)
        ->toBe('https://10.6.0.1')
        ->and($row->gateway_wg_ip)
        ->toBe('10.6.0.1')
        ->and($row->ca_sha256)
        ->toBe('deadbeef')
        ->and($row->ca_pem_path)
        ->toBe('/tmp/ca.pem')
        ->and($row->singleton_key)
        ->toBe(LocalGatewaySettings::SINGLETON_KEY);
});

it('prefers a later created_at even when that row has a lower id', function (): void {
    restore_pre_singleton_local_gateway_settings_schema();

    $older = now()->subDay();
    $newer = now();

    $canonicalId = DB::table('local_gateway_settings')->insertGetId([
        'gateway_url' => 'https://10.6.0.1',
        'gateway_wg_ip' => '10.6.0.1',
        'ca_sha256' => 'deadbeef',
        'ca_pem_path' => '/tmp/ca.pem',
        'trusted_at' => $newer,
        'created_at' => $newer,
        'updated_at' => $newer,
    ]);
    DB::table('local_gateway_settings')->insert([
        'gateway_url' => 'https://old.example',
        'gateway_wg_ip' => '10.6.0.99',
        'ca_sha256' => 'old-sha',
        'ca_pem_path' => '/tmp/old.pem',
        'trusted_at' => $older,
        'created_at' => $older,
        'updated_at' => $older,
    ]);

    run_enforce_singleton_local_gateway_settings_migration();

    $row = DB::table('local_gateway_settings')->sole();

    expect($row->id)
        ->toBe($canonicalId)
        ->and($row->gateway_url)
        ->toBe('https://10.6.0.1')
        ->and($row->ca_sha256)
        ->toBe('deadbeef');
});

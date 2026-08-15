<?php

declare(strict_types=1);

use App\Models\App;
use App\Models\AppWebSocketBinding;
use App\Models\Instance;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('creates the app websocket bindings table with the expected columns and broad types', function (): void {
    expect(Schema::hasTable('app_websocket_bindings'))
        ->toBeTrue()
        ->and(Schema::hasColumns('app_websocket_bindings', [
            'id',
            'instance_id',
            'enabled',
            'reverb_app_id',
            'reverb_app_key',
            'reverb_app_secret',
            'allowed_origins',
            'public_hosts',
            'created_at',
            'updated_at',
        ]))
        ->toBeTrue()
        ->and(Schema::hasColumn('app_websocket_bindings', 'app_id'))
        ->toBeFalse()
        ->and(Schema::getColumnType('app_websocket_bindings', 'enabled'))
        ->toBeIn(['boolean', 'tinyint'])
        ->and(Schema::getColumnType('app_websocket_bindings', 'reverb_app_id'))
        ->toBeIn(['string', 'varchar'])
        ->and(Schema::getColumnType('app_websocket_bindings', 'reverb_app_key'))
        ->toBeIn(['string', 'varchar'])
        ->and(Schema::getColumnType('app_websocket_bindings', 'reverb_app_secret'))
        ->toBe('text')
        ->and(Schema::getColumnType('app_websocket_bindings', 'allowed_origins'))
        ->toBeIn(['json', 'text'])
        ->and(Schema::getColumnType('app_websocket_bindings', 'public_hosts'))
        ->toBeIn(['json', 'text']);
});

it('stores app websocket bindings with encrypted secret material', function (): void {
    $instance = Instance::factory()->create();

    $binding = AppWebSocketBinding::query()->create([
        'instance_id' => $instance->id,
        'enabled' => true,
        'reverb_app_id' => 'docs',
        'reverb_app_key' => 'public-key',
        'reverb_app_secret' => 'server-secret',
        'allowed_origins' => ['https://example.com'],
        'public_hosts' => ['ws.example.com'],
    ]);

    expect($binding->fresh())
        ->enabled->toBeTrue()
        ->reverb_app_secret->toBe('server-secret')
        ->allowed_origins->toBe(['https://example.com'])
        ->public_hosts->toBe(['ws.example.com'])
        ->and($instance->fresh()->webSocketBinding->is($binding))->toBeTrue();

    expect(DB::table('app_websocket_bindings')->whereKey($binding->id)->value('reverb_app_secret'))
        ->not
        ->toBe('server-secret');
});

it('enforces one websocket binding per instance while allowing sibling instances', function (): void {
    $app = App::factory()->create();
    $development = Instance::factory()->for($app)->create(['name' => 'development']);
    $production = Instance::factory()->for($app)->create(['name' => 'production']);

    AppWebSocketBinding::factory()->create([
        'instance_id' => $development->id,
    ]);
    AppWebSocketBinding::factory()->create([
        'instance_id' => $production->id,
    ]);

    expect(fn () => AppWebSocketBinding::factory()->create([
        'instance_id' => $development->id,
    ]))
        ->toThrow(QueryException::class);
});

it('cascades websocket bindings when the instance is deleted', function (): void {
    $instance = Instance::factory()->has(AppWebSocketBinding::factory(), 'webSocketBinding')->create();

    expect(AppWebSocketBinding::query()->whereBelongsTo($instance)->count())->toBe(1);

    $instance->delete();

    expect(AppWebSocketBinding::query()->count())->toBe(0);
});

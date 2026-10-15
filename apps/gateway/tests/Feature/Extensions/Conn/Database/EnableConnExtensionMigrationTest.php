<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use Illuminate\Database\Migrations\Migration;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnProfile;

function conn_default_migration(): Migration
{
    $migration = require dirname(__DIR__, 5).'/extensions/conn/database/migrations/2026_11_10_000001_enable_conn_extension_for_existing_conn.php';
    expect($migration)->toBeInstanceOf(Migration::class);

    return $migration;
}

beforeEach(function (): void {
    // The directory's setup turns the switch on; each case starts from an install that never set it.
    app(ExtensionStore::class)->set('conn', false);
});

it('leaves conn off on an install without Conn records', function (): void {
    conn_default_migration()->up();

    expect(app(ExtensionStore::class)->enabled('conn'))->toBeFalse();
});

it('turns conn on for an install with a profile', function (): void {
    ConnProfile::query()->create(['name' => 'Nick', 'settings' => [], 'settings_version' => 1]);

    conn_default_migration()->up();
    expect(app(ExtensionStore::class)->enabled('conn'))->toBeTrue();

    conn_default_migration()->down();
    expect(app(ExtensionStore::class)->enabled('conn'))->toBeFalse();
});

it('turns conn on for an install with a registered T3 server', function (): void {
    ConnEnvironment::query()->create([
        'environment_id' => 'env-1', 'label' => 'Beast', 'url' => 'https://t3.beast.orbit',
        'admin_session' => 'session', 'admin_session_expires_at' => now()->addDay(), 'registered_at' => now(),
    ]);

    conn_default_migration()->up();

    expect(app(ExtensionStore::class)->enabled('conn'))->toBeTrue();
});

<?php

declare(strict_types=1);

use App\Domain\Extensions\ExtensionStore;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

function vault_default_migration(): Migration
{
    $migration = require dirname(__DIR__, 5).'/extensions/vault/database/migrations/2026_11_10_000000_enable_vault_extension_for_existing_vault.php';
    expect($migration)->toBeInstanceOf(Migration::class);

    return $migration;
}

beforeEach(function (): void {
    // The directory's setup turns the switch on; each case starts from an install that never set it.
    app(ExtensionStore::class)->set('vault', false);
});

it('leaves vault off on an install without a Vaultwarden setup', function (): void {
    vault_default_migration()->up();

    expect(app(ExtensionStore::class)->enabled('vault'))->toBeFalse();
});

it('turns vault on for an install with a stored Vaultwarden setup', function (): void {
    app(SettingRepository::class)->put(new SettingScope(SettingScopeType::Gateway), 'vault.configuration', '{}');

    vault_default_migration()->up();

    expect(app(ExtensionStore::class)->enabled('vault'))->toBeTrue();
});

it('turns vault on for an install with a published item', function (): void {
    DB::table('vault_items')->insert([
        'source' => 'database_user:1', 'cipher_id' => 'cipher-1', 'fingerprint' => str_repeat('a', 64),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    vault_default_migration()->up();
    expect(app(ExtensionStore::class)->enabled('vault'))->toBeTrue();

    vault_default_migration()->down();
    expect(app(ExtensionStore::class)->enabled('vault'))->toBeFalse();
});

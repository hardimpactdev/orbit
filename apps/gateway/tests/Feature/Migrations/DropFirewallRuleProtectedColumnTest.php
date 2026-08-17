<?php

declare(strict_types=1);

use App\Models\FirewallRule;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function drop_firewall_rule_protected_column_migration_path(): string
{
    return database_path('migrations/2026_08_17_180000_drop_protected_from_firewall_rules_table.php');
}

function drop_firewall_rule_protected_column_migration(): Migration
{
    $migrationPath = drop_firewall_rule_protected_column_migration_path();

    expect($migrationPath)->toBeFile();

    $migration = require $migrationPath;

    if (! $migration instanceof Migration) {
        throw new RuntimeException('Drop firewall rule protected column migration must be a Migration.');
    }

    return $migration;
}

function restore_firewall_rules_protected_column(): void
{
    if (Schema::hasColumn('firewall_rules', 'protected')) {
        return;
    }

    Schema::table('firewall_rules', static function (Blueprint $table): void {
        $table->boolean('protected')->default(false);
    });
}

it('drops the stored protected column and derives protection from owner', function (): void {
    restore_firewall_rules_protected_column();

    $node = createTestAppHostNode(['name' => 'app-1', 'platform' => 'ubuntu']);
    $now = now();
    $id = DB::table('firewall_rules')->insertGetId([
        'node_id' => $node->id,
        'name' => 'legacy-system-rule',
        'direction' => 'incoming',
        'action' => 'allow',
        'source' => 'any',
        'destination' => null,
        'port' => '443',
        'protocol' => 'tcp',
        'reason' => 'legacy contradiction',
        'source_hash' => hash('sha256', 'legacy-system-rule'),
        'address_family' => 'v4',
        'interface' => null,
        'owner' => 'system',
        'protected' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    expect((bool) DB::table('firewall_rules')->where('id', $id)->value('protected'))->toBeFalse();

    drop_firewall_rule_protected_column_migration()->up();

    expect(Schema::hasColumn('firewall_rules', 'protected'))->toBeFalse();

    $rule = FirewallRule::query()->findOrFail($id);

    expect($rule->owner)
        ->toBe('system')
        ->and($rule->protected)
        ->toBeTrue();
});

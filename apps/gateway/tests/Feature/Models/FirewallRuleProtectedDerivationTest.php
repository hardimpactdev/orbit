<?php

declare(strict_types=1);

use App\Models\FirewallRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function insertFirewallRuleOwnershipRow(array $overrides = []): FirewallRule
{
    $node = createTestAppHostNode(['platform' => 'ubuntu']);
    $now = now();
    $row = array_merge([
        'node_id' => $node->id,
        'name' => 'ownership-row',
        'direction' => 'incoming',
        'action' => 'allow',
        'source' => 'any',
        'destination' => null,
        'port' => '443',
        'protocol' => 'tcp',
        'reason' => 'ownership derivation',
        'source_hash' => hash('sha256', 'ownership-row'),
        'address_family' => 'v4',
        'interface' => null,
        'owner' => 'user',
        'protected' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ], $overrides);

    if (! Schema::hasColumn('firewall_rules', 'protected')) {
        unset($row['protected']);
    }

    $id = DB::table('firewall_rules')->insertGetId($row);

    return FirewallRule::query()->findOrFail($id);
}

it('treats user-owned firewall rules as unprotected', function (): void {
    $rule = insertFirewallRuleOwnershipRow([
        'owner' => 'user',
        'protected' => true,
    ]);

    expect($rule->protected)->toBeFalse();
});

it('treats non-user firewall rules as protected even when a stored boolean is false', function (): void {
    $rule = insertFirewallRuleOwnershipRow([
        'name' => 'system-owned',
        'owner' => 'system',
        'protected' => false,
    ]);

    expect($rule->protected)->toBeTrue();
});

it('flips computed protection when ownership changes', function (): void {
    $rule = insertFirewallRuleOwnershipRow([
        'owner' => 'user',
        'protected' => false,
    ]);

    expect($rule->protected)->toBeFalse();

    $rule->owner = 'system';

    expect($rule->protected)->toBeTrue();

    $rule->owner = 'user';

    expect($rule->protected)->toBeFalse();
});

it('defaults a missing owner to user-owned and unprotected on save', function (): void {
    $rule = FirewallRule::factory()->create([
        'owner' => null,
    ]);

    expect($rule->refresh()->owner)
        ->toBe('user')
        ->and($rule->protected)
        ->toBeFalse();
});

it('throws when assigning the derived protected attribute', function (): void {
    $rule = FirewallRule::factory()->create([
        'owner' => 'user',
    ]);

    expect(fn () => $rule->protected = true)
        ->toThrow(LogicException::class, 'FirewallRule.protected is derived from owner and cannot be assigned.')
        ->and(fn () => FirewallRule::factory()->create(['protected' => false]))
        ->toThrow(LogicException::class, 'FirewallRule.protected is derived from owner and cannot be assigned.')
        ->and($rule->refresh()->protected)
        ->toBeFalse();
});

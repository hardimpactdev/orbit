<?php

declare(strict_types=1);

use App\Domain\Routes\SharedPoolMerge;

// The cases of withdraw.rs's merge_pool tests (apps/agent/crates/route/src/tests.rs), which this merge replaces.

it('removes the withdrawn scope from a pool that still holds its rendered-from state', function (): void {
    expect(SharedPoolMerge::merge("[orbit]\n[shop]\n", "[orbit]\n", "[orbit]\n[shop]\n", ['1000', 'staff']))->toBe("[orbit]\n");
});

it('refuses a changed scope of its own and keeps an unrelated one', function (): void {
    expect(SharedPoolMerge::merge("[orbit]\n[shop]\n", "[orbit]\n", "[orbit]\n[shop]\nchanged = 1\n", ['1000', 'staff']))->toBeNull()
        ->and(SharedPoolMerge::merge("[orbit]\n[shop]\n", "[orbit]\n", "[foreign]\n[orbit]\n[shop]\n", ['1000', 'staff']))->toBe("[foreign]\n[orbit]\n");
});

it('keeps a surviving scope whose group is named or numeric byte for byte', function (string $live, string $kept): void {
    $from = "[orbit]\ngroup = __ORBIT_NODE_GROUP__\n[shop]\ngroup = __ORBIT_NODE_GROUP__\n";

    expect(SharedPoolMerge::merge($from, "[orbit]\ngroup = __ORBIT_NODE_GROUP__\n", $live, ['1000', 'staff']))->toBe($kept);
})->with([
    'named' => ["[orbit]\ngroup = staff\n[shop]\ngroup = staff\n", "[orbit]\ngroup = staff\n"],
    'mixed' => ["[orbit]\ngroup = 1000\n[shop]\ngroup = staff\n", "[orbit]\ngroup = 1000\n"],
]);

it('leaves an absent unchanged scope absent', function (): void {
    expect(SharedPoolMerge::merge("[seven]\n[nine]\n", "[seven]\n", "[eleven]\n[nine]\n", ['1000', 'staff']))->toBe("[eleven]\n");
});

it('keeps a sibling scope byte for byte across the render separator', function (string $live, string $kept): void {
    $from = "[seven]\ngroup = __ORBIT_NODE_GROUP__\n\n[nine]\ngroup = __ORBIT_NODE_GROUP__\n";

    expect(SharedPoolMerge::merge($from, "[seven]\ngroup = __ORBIT_NODE_GROUP__\n", $live, ['1000', 'staff']))->toBe($kept);
})->with([
    'sibling absent' => ["[nine]\ngroup = staff\n", ''],
    'sibling present' => ["[seven]\ngroup = staff\n\n[nine]\ngroup = staff\n", "[seven]\ngroup = staff\n\n"],
]);

it('writes a changed scope with the gid and keeps the text before the first scope', function (): void {
    $from = "; pools\n[shop]\ngroup = __ORBIT_NODE_GROUP__\n";
    $desired = "; pools\n[shop]\ngroup = __ORBIT_NODE_GROUP__\nphp_value[x] = 1\n";

    expect(SharedPoolMerge::merge($from, $desired, "; live\n[shop]\ngroup = staff\n", ['1000', 'staff']))
        ->toBe("; live\n[shop]\ngroup = 1000\nphp_value[x] = 1\n");
});

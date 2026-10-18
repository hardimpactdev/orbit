<?php

declare(strict_types=1);

use App\E2E\Value\TopologyNodeCatalog;
use App\E2E\Value\TopologyNodePurpose;
use App\E2E\Value\TopologyRecipe;

it('reads every Node of the catalog file in composition order', function () {
    $catalog = TopologyNodeCatalog::load();

    expect(array_keys($catalog->nodes))->toBe(['gateway', 'app-dev', 'app-prod', 'app-prod-2', 'operator'])
        ->and($catalog->declarable())->toBe(['app-dev', 'app-prod', 'app-prod-2'])
        ->and($catalog->defaults)->toBe(['app-dev', 'app-prod'])
        ->and($catalog->extraRoles)->toBe(['ingress'])
        ->and($catalog->ownClusterRoles)->toBe(['ingress'])
        ->and($catalog->node('app-prod')['memory'])->toBe('1GiB');
});

it('gives each declarable Node the workload purpose, a base role, and a TLD', function () {
    foreach (TopologyNodeCatalog::load()->nodes as $key => $node) {
        if ($node['declarable']) {
            expect($node['purpose'])->toBe(TopologyNodePurpose::Workload, $key)
                ->and($node['base_role'])->not->toBeNull()
                ->and($node['tld'])->not->toBeNull();
        } else {
            expect($node['purpose'])->toBe(TopologyNodePurpose::from($key), $key)
                ->and($node['base_role'])->toBeNull();
        }
    }
});

it('names the Gateway and operator Nodes by their purpose', function () {
    expect(TopologyNodePurpose::Gateway->roles())->toBe(['gateway', 'vpn'])
        ->and(TopologyNodePurpose::Operator->roles())->toBe([])
        ->and(TopologyNodePurpose::Workload->roles())->toBe([]);
});

it('keeps the Gateway first, the operator last, and every address unique and ascending', function () {
    $nodes = TopologyNodeCatalog::load()->nodes;
    $addresses = array_column($nodes, 'address');
    $sorted = $addresses;
    sort($sorted);

    expect(array_key_first($nodes))->toBe('gateway')
        ->and(array_key_last($nodes))->toBe('operator')
        ->and($addresses)->toBe(array_values(array_unique($addresses)))
        ->and($addresses)->toBe($sorted);
});

it('ends the DHCP range at the highest address the recipe uses', function () {
    expect(TopologyRecipe::fromOption('')->lastAddress())->toBe(14)
        ->and(TopologyRecipe::fromOption('app-dev,app-prod,app-prod-2')->node('app-prod-2')->memory)->toBe('1GiB');
});

it('refuses a catalog with a missing or mistyped field', function (string $json) {
    expect(fn () => TopologyNodeCatalog::fromJson($json))->toThrow(RuntimeException::class);
})->with([
    'no nodes' => ['{"default":[],"extra_roles":[],"own_cluster_roles":[]}'],
    'address as text' => ['{"nodes":{"gateway":{"declarable":false,"address":"10","checkout":true,"memory":"1GiB"}},"default":[],"extra_roles":[],"own_cluster_roles":[]}'],
    'an undeclarable Node with no purpose' => ['{"nodes":{"builder":{"declarable":false,"address":10,"checkout":true,"memory":"1GiB"}},"default":[],"extra_roles":[],"own_cluster_roles":[]}'],
    'an undeclarable workload' => ['{"nodes":{"workload":{"declarable":false,"address":10,"checkout":true,"memory":"1GiB"}},"default":[],"extra_roles":[],"own_cluster_roles":[]}'],
    'no extra roles' => ['{"nodes":{},"default":[]}'],
]);

<?php

declare(strict_types=1);

use App\E2E\Value\RoleImage;
use App\E2E\Value\TopologyNode;
use App\E2E\Value\TopologyRecipe;

it('always composes the Gateway and the operator around the declared workload Nodes', function (array $declared, array $keys, int $vms) {
    $recipe = TopologyRecipe::compose($declared);

    expect($recipe->nodeKeys())->toBe($keys)
        ->and($recipe->vmCount())->toBe($vms)
        ->and($recipe->node('gateway')->roles)->toBe(['gateway', 'vpn'])
        ->and($recipe->node('operator')->roles)->toBe([])
        ->and($recipe->node('operator')->isContainer())->toBeTrue();
})->with([
    'gateway only' => [[], ['gateway', 'operator'], 1],
    'app-dev' => [['app-dev'], ['gateway', 'app-dev', 'operator'], 2],
    'default' => [['app-dev', 'app-prod'], ['gateway', 'app-dev', 'app-prod', 'operator'], 3],
    'full, declared out of order' => [['app-prod-2', 'app-prod', 'app-dev'], ['gateway', 'app-dev', 'app-prod', 'app-prod-2', 'operator'], 4],
]);

it('gives every Node its role image, fixed address, WireGuard address, roles, and checkout', function () {
    $recipe = TopologyRecipe::compose(TopologyRecipe::WORKLOADS);
    $rows = array_map(static fn (TopologyNode $node): array => [
        $node->key,
        $node->image,
        $node->address,
        $node->wireGuardAddress(),
        $node->roles,
        $node->checkout,
    ], $recipe->nodes);

    expect($rows)->toBe([
        ['gateway', RoleImage::Gateway, 10, '10.44.0.1', ['gateway', 'vpn'], true],
        ['app-dev', RoleImage::AppDev, 11, '10.44.0.2', ['app-dev'], true],
        ['app-prod', RoleImage::AppProd, 12, '10.44.0.3', ['app-prod'], false],
        ['app-prod-2', RoleImage::AppProd, 13, '10.44.0.4', ['app-prod'], false],
        ['operator', RoleImage::Operator, 14, '10.44.0.5', [], true],
    ])
        ->and($recipe->checkoutNodeKeys())->toBe(['gateway', 'app-dev', 'operator'])
        ->and($recipe->images())->toBe([RoleImage::Gateway, RoleImage::AppDev, RoleImage::AppProd, RoleImage::Operator]);
});

it('reads the --nodes option, with app-dev and app-prod by default and an empty list for none', function (?string $option, array $workloads) {
    expect(TopologyRecipe::fromOption($option)->workloads)->toBe($workloads);
})->with([
    'absent' => [null, ['app-dev', 'app-prod']],
    'empty' => ['', []],
    'spaces' => [' app-prod , app-dev ', ['app-dev', 'app-prod']],
    'full' => ['app-dev,app-prod,app-prod-2', ['app-dev', 'app-prod', 'app-prod-2']],
]);

it('refuses an unknown, repeated, or unsupported declaration', function (array $declared, string $message) {
    expect(fn () => TopologyRecipe::compose($declared))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown' => [['app-staging'], 'A declared Node must be one of app-dev, app-prod, app-prod-2.'],
    'gateway is always present' => [['gateway'], 'A declared Node must be one of'],
    'repeated' => [['app-dev', 'app-dev'], 'The Node [app-dev] is declared twice.'],
    'second production Node alone' => [['app-prod-2'], 'The Node [app-prod-2] requires [app-prod].'],
]);

it('round trips its declaration and refuses one out of order', function () {
    $recipe = TopologyRecipe::compose(['app-prod', 'app-dev']);

    expect($recipe->toArray())->toBe(['workloads' => ['app-dev', 'app-prod']])
        ->and(TopologyRecipe::fromArray($recipe->toArray())->nodeKeys())->toBe($recipe->nodeKeys())
        ->and(fn () => TopologyRecipe::fromArray(['workloads' => ['app-prod', 'app-dev']]))
        ->toThrow(InvalidArgumentException::class, 'out of order')
        ->and(fn () => TopologyRecipe::fromArray(['nodes' => []]))
        ->toThrow(InvalidArgumentException::class, 'schema is invalid');
});

it('adds the declared roles to their Node, in canonical order', function () {
    $recipe = TopologyRecipe::fromOption('app-prod+ingress,app-dev');

    expect($recipe->workloads)->toBe(['app-dev', 'app-prod+ingress'])
        ->and($recipe->node('app-dev')->roles)->toBe(['app-dev'])
        ->and($recipe->node('app-prod')->roles)->toBe(['app-prod', 'ingress'])
        ->and($recipe->extraRoles($recipe->node('app-prod')))->toBe(['ingress'])
        ->and($recipe->extraRoles($recipe->node('app-dev')))->toBe([])
        ->and($recipe->assignments()['app-prod'])->toBe(['app-prod', 'ingress'])
        ->and(TopologyRecipe::fromArray($recipe->toArray())->workloads)->toBe($recipe->workloads)
        ->and(fn () => TopologyRecipe::fromArray(['workloads' => ['app-prod+ingress', 'app-dev']]))
        ->toThrow(InvalidArgumentException::class, 'out of order');
});

it('refuses an unknown or repeated extra role', function (string $option, string $message) {
    expect(fn () => TopologyRecipe::fromOption($option))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown role' => ['app-prod+router', 'The Node [app-prod] can only add the roles ingress.'],
    'base role again' => ['app-dev+app-dev', 'can only add the roles'],
    'repeated role' => ['app-prod+ingress+ingress', 'The Node [app-prod] adds a role twice.'],
    'same Node twice with other roles' => ['app-prod,app-prod+ingress', 'The Node [app-prod] is declared twice.'],
    'empty role' => ['app-prod+', 'can only add the roles'],
]);

it('names a missing Node', function () {
    expect(fn () => TopologyRecipe::compose(['app-dev'])->node('app-prod'))
        ->toThrow(InvalidArgumentException::class, 'The topology has no Node [app-prod].');
});

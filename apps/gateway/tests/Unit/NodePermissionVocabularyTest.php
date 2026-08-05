<?php

declare(strict_types=1);

use App\Services\Nodes\Access\NodePermissionNormalizer;
use App\Services\Nodes\Access\NodePermissionRegistry;

/**
 * Post-cutover permission vocabulary: registry exposes App/Instance tokens only;
 * Project workload tokens fail closed through the normalizer with no rewrite layer.
 */
it('registers only canonical app and instance workload permissions after cutover', function (): void {
    $permissions = app(NodePermissionRegistry::class)->all();

    expect($permissions)
        ->toContain(
            'app:*',
            'app:read',
            'app:write',
            'app:list',
            'app:show',
            'app:new',
            'app:remove',
            'instance:*',
            'instance:read',
            'instance:write',
            'instance:register',
            'instance:credentials',
            'instance:mount',
            'solo:project:list',
        )
        ->not->toContain(
            'project:*',
            'project:read',
            'project:write',
            'project:list',
            'project:show',
            'project:new',
            'project:remove',
        );
});

it('rejects project workload tokens through the normalizer instead of rewriting them', function (): void {
    $normalizer = app(NodePermissionNormalizer::class);

    foreach (['project:read', 'project:write', 'project:*', 'project:list', 'project:new'] as $token) {
        expect(fn () => $normalizer->normalize([$token]))
            ->toThrow(InvalidArgumentException::class, "Unknown permission [{$token}].");
    }
});

it('accepts and normalizes canonical app and instance tokens without dual legacy storage', function (): void {
    $normalized = app(NodePermissionNormalizer::class)->normalize([
        'app:read',
        'instance:read',
        'app:read',
        'node:read',
    ]);

    expect($normalized->permissions)
        ->toBe(['app:read', 'instance:read', 'node:read'])
        ->and($normalized->removed)
        ->toBeEmpty();
});

it('rejects removed predecessor tokens as unknown rather than silently dropping them', function (): void {
    $normalizer = app(NodePermissionNormalizer::class);
    $registry = app(NodePermissionRegistry::class);

    foreach (['app:agent', 'app:prune', 'instance:agent', 'instance:prune', 'agent-ide:message'] as $token) {
        expect($registry->isKnown($token))->toBeFalse();
        expect(fn () => $normalizer->normalize([$token, 'instance:read']))
            ->toThrow(InvalidArgumentException::class, "Unknown permission [{$token}].");
    }
});

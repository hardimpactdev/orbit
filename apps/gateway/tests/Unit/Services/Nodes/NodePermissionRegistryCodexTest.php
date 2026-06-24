<?php

declare(strict_types=1);

use App\Services\Nodes\Access\NodePermissionRegistry;
use Tests\TestCase;

uses(TestCase::class);

describe(NodePermissionRegistry::class, function (): void {
    it('registers app codex and lets app write imply it', function (): void {
        $registry = app(NodePermissionRegistry::class);

        expect($registry->all())
            ->toContain('app:codex')
            ->and($registry->allows(['app:codex'], 'app:codex'))
            ->toBeTrue()
            ->and($registry->allows(['app:write'], 'app:codex'))
            ->toBeTrue()
            ->and($registry->allows(['app:read'], 'app:codex'))
            ->toBeFalse();
    });
});

<?php

declare(strict_types=1);

use App\Services\Nodes\Access\NodePermissionRegistry;
use Tests\TestCase;

uses(TestCase::class);

describe(NodePermissionRegistry::class, function (): void {
    it('registers codex app without app write implying it', function (): void {
        $registry = app(NodePermissionRegistry::class);

        expect($registry->all())
            ->toContain('codex:app')
            ->and($registry->allows(['codex:app'], 'codex:app'))
            ->toBeTrue()
            ->and($registry->allows(['codex:*'], 'codex:app'))
            ->toBeTrue()
            ->and($registry->allows(['app:write'], 'codex:app'))
            ->toBeFalse()
            ->and($registry->allows(['app:read'], 'codex:app'))
            ->toBeFalse();
    });
});

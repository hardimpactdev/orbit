<?php

declare(strict_types=1);

use App\Services\Updates\CheckoutPathResolver;

describe('CheckoutPathResolver', function (): void {
    it('resolves the repository root when the CLI app lives under apps/cli', function (): void {
        expect((new CheckoutPathResolver)->resolve())->toBe(dirname(base_path(), 2));
    });
});

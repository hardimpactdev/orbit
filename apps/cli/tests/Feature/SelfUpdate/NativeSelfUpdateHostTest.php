<?php

declare(strict_types=1);

use App\Services\SelfUpdate\NativeSelfUpdateHost;
use App\Services\SelfUpdate\RunningBinaryKind;

describe(NativeSelfUpdateHost::class, function (): void {
    it('reports a source checkout when no PHAR runs', function (): void {
        $binary = new NativeSelfUpdateHost()->runningBinary();

        expect($binary->kind)->toBe(RunningBinaryKind::Source)
            ->and($binary->path)->toBeNull();
    });
});

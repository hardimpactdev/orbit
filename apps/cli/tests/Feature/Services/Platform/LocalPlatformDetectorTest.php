<?php

declare(strict_types=1);

use App\Services\Platform\LocalPlatformDetector;

describe(LocalPlatformDetector::class, function (): void {
    it('normalizes macOS versions into platform identifiers', function (): void {
        expect(app(LocalPlatformDetector::class)->macOsIdentifier('15.5.1'))->toBe('macos_15-5-1');
    });

    it('normalizes Linux os-release values into platform identifiers', function (): void {
        $osRelease = <<<'TEXT'
            ID=ubuntu
            VERSION_ID="24.04"
            TEXT;

        expect(app(LocalPlatformDetector::class)->linuxIdentifier($osRelease))->toBe('ubuntu_24-04');
    });
});

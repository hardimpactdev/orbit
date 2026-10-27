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

    it('maps each machine to its release platform', function (string $osFamily, string $machine, ?string $platform): void {
        expect(NativeSelfUpdateHost::releasePlatform($osFamily, $machine))->toBe($platform);
    })->with([
        'Linux x86_64' => ['Linux', 'x86_64', 'linux-x86_64'],
        'Linux amd64' => ['Linux', 'AMD64', 'linux-x86_64'],
        'Apple silicon' => ['Darwin', 'arm64', 'macos-arm64'],
        'Linux aarch64' => ['Linux', 'aarch64', null],
        'Linux arm64' => ['Linux', 'arm64', null],
        'Intel Mac' => ['Darwin', 'x86_64', null],
        'Windows' => ['Windows', 'AMD64', null],
    ]);
});

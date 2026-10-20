<?php

declare(strict_types=1);

use App\Domain\GatewayReleases\GatewayVersion;
use Illuminate\Support\Facades\File;

describe('the running Gateway version', function (): void {
    it('reports the commit in REVISION at the root of its release unless APP_VERSION is set', function (): void {
        $release = sys_get_temp_dir().'/orbit-release-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($release.'/apps/gateway');
        file_put_contents($release.'/REVISION', str_repeat('c', 40)."\n");
        $basePath = base_path();
        config(['app.version' => null]);
        app()->setBasePath($release.'/apps/gateway');

        try {
            expect(GatewayVersion::current())->toBe(str_repeat('c', 40));

            config(['app.version' => '1.2.3']);

            expect(GatewayVersion::current())->toBe('1.2.3');
        } finally {
            app()->setBasePath($basePath);
            File::deleteDirectory($release);
        }
    });
});

<?php

declare(strict_types=1);

use App\E2E\Support\DockerHost;
use App\E2E\Support\DockerInstance;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ECurrentCheckout;
use Illuminate\Support\Facades\File;

afterEach(function (): void {
    E2ECurrentCheckout::flushCache();
});

it('hydrates reused vendor dependencies inside the current checkout instead of symlinking to the base checkout', function (): void {
    $method = new ReflectionMethod(E2ECurrentCheckout::class, 'reusePreparedVendorWithLocalAutoloadCommand');

    $command = $method->invoke(
        null,
        'apps/gateway',
        '/home/orbit/orbit-current-base-1234567890',
        "else echo 'missing vendor'; exit 127",
    );

    expect($command)
        ->toContain('cp -al "$path" "$target"/')
        ->toContain('cp -a --reflink=always "$path" "$target"/')
        ->not->toContain('ln -s');
});

it('uses the archive checkout path for prepared docker host-launcher instances', function (): void {
    $method = new ReflectionMethod(E2ECurrentCheckout::class, 'sourceMountedCheckoutPath');
    $instance = new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-gateway');

    expect($method->invoke(null, $instance, 'orbit', true))->toBeNull();
});

it('keeps explicit source-mounted docker checkout paths', function (): void {
    $method = new ReflectionMethod(E2ECurrentCheckout::class, 'sourceMountedCheckoutPath');
    $instance = new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-gateway', null, '/home/orbit/orbit');

    expect($method->invoke(null, $instance, 'orbit', true))->toBe('/home/orbit/orbit');
});

it('passes Docker gateway state environment through the current checkout wrapper', function (): void {
    $script = E2ECurrentCheckout::orbitWrapperScript('/home/orbit/orbit-current', dockerRuntime: true);

    expect($script)
        ->toContain("--env 'ORBIT_CONFIG_ROOT=/home/orbit/.config/orbit'")
        ->toContain("--env 'DB_CONNECTION=sqlite'")
        ->toContain("--env 'DB_DATABASE=/home/orbit/.config/orbit/gateway.sqlite'")
        ->toContain("--env 'SESSION_DRIVER=file'");
});

it('writes direct CLI gateway config while refreshing current checkout gateway settings', function (): void {
    $method = new ReflectionMethod(E2ECurrentCheckout::class, 'localGatewaySettingsCommand');

    $command = $method->invoke(null, '/home/orbit/orbit-current', '10.6.0.2', true);

    expect($command)
        ->toContain('/api/ca/root')
        ->toContain('config.json')
        ->toContain('wireguard_https')
        ->toContain('exit(1)')
        ->not->toContain('gateway:add');
});

it('prunes stale checkout archive cache tarballs and locks', function (): void {
    $previousCacheDirectory = getenv('ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR');
    $cacheDirectory = sys_get_temp_dir().'/orbit-checkout-cache-'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($cacheDirectory);

    try {
        putenv("ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR={$cacheDirectory}");

        $currentArchive = $cacheDirectory.'/'.E2ECurrentCheckout::treeHash().'.tar.gz';
        $currentLock = "{$currentArchive}.lock";
        $staleArchive = "{$cacheDirectory}/stale-tree.tar.gz";
        $staleLock = "{$staleArchive}.lock";

        File::put($currentArchive, 'current archive');
        File::put($currentLock, 'current lock');
        File::put($staleArchive, 'stale archive');
        File::put($staleLock, 'stale lock');
        touch($staleArchive, time() - 90000);
        touch($staleLock, time() - 90000);

        $method = new ReflectionMethod(E2ECurrentCheckout::class, 'cachedArchive');

        expect($method->invoke(null))->toBe($currentArchive)
            ->and($currentArchive)->toBeFile()
            ->and($currentLock)->toBeFile()
            ->and($staleArchive)->not->toBeFile()
            ->and($staleLock)->not->toBeFile();
    } finally {
        $previousCacheDirectory === false
            ? putenv('ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR')
            : putenv("ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR={$previousCacheDirectory}");

        File::deleteDirectory($cacheDirectory);
    }
});

it('ignores archive manifest paths that disappear before hashing', function (): void {
    $stablePath = repo_path('tmp-e2e-tree-hash-stable-'.bin2hex(random_bytes(4)).'.txt');
    $deletedPath = repo_path('tmp-e2e-tree-hash-deleted-'.bin2hex(random_bytes(4)).'.txt');
    $stableRelativePath = basename($stablePath);
    $deletedRelativePath = basename($deletedPath);

    File::put($stablePath, 'stable');
    File::put($deletedPath, 'deleted');
    File::delete($deletedPath);

    try {
        $method = new ReflectionMethod(E2ECurrentCheckout::class, 'treeHashForManifest');

        expect($method->invoke(null, [$stableRelativePath, $deletedRelativePath]))
            ->toBe($method->invoke(null, [$stableRelativePath]));
    } finally {
        File::delete($stablePath);
        File::delete($deletedPath);
    }
});

it('prunes stale checkout archive artifacts while building temporary archives', function (): void {
    $previousCacheDirectory = getenv('ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR');
    $cacheDirectory = sys_get_temp_dir().'/orbit-checkout-cache-'.bin2hex(random_bytes(6));
    $staleTemporaryArchive = sys_get_temp_dir().'/orbit-current-stale-prune-'.bin2hex(random_bytes(6)).'.tar.gz';
    $builtArchive = null;

    File::ensureDirectoryExists($cacheDirectory);

    try {
        putenv("ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR={$cacheDirectory}");

        $staleArchive = "{$cacheDirectory}/stale-tree.tar.gz";
        $staleLock = "{$staleArchive}.lock";

        File::put($staleArchive, 'stale archive');
        File::put($staleLock, 'stale lock');
        File::put($staleTemporaryArchive, 'stale temporary archive');
        touch($staleArchive, time() - 90000);
        touch($staleLock, time() - 90000);
        touch($staleTemporaryArchive, time() - 90000);

        $builtArchive = E2ECurrentCheckout::buildArchive();

        expect($builtArchive)->toBeFile()
            ->and($staleArchive)->not->toBeFile()
            ->and($staleLock)->not->toBeFile()
            ->and($staleTemporaryArchive)->not->toBeFile();
    } finally {
        if (is_string($builtArchive)) {
            File::delete($builtArchive);
        }

        $previousCacheDirectory === false
            ? putenv('ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR')
            : putenv("ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR={$previousCacheDirectory}");

        File::deleteDirectory($cacheDirectory);
        File::delete($staleTemporaryArchive);
    }
});

it('excludes generated versioned Orbit binaries from checkout archives', function (): void {
    $binary = repo_path('bin/orbit-binary-test');

    File::put($binary, 'generated binary');

    try {
        $method = new ReflectionMethod(E2ECurrentCheckout::class, 'shouldIncludeArchivePath');

        expect($method->invoke(null, 'bin/orbit-binary-test'))->toBeFalse();
    } finally {
        File::delete($binary);
    }
});

it('excludes transient repo-root archive manifest fixtures from checkout archives', function (): void {
    $manifest = repo_path('tmp-e2e-archive-manifest-'.bin2hex(random_bytes(4)).'.txt');

    File::put($manifest, 'transient manifest fixture');

    try {
        $method = new ReflectionMethod(E2ECurrentCheckout::class, 'shouldIncludeArchivePath');

        expect($method->invoke(null, basename($manifest)))->toBeFalse();
    } finally {
        File::delete($manifest);
    }
});

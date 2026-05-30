<?php

declare(strict_types=1);

namespace App\Services\Updates;

/**
 * Resolves the host Orbit install root — the directory where the Orbit source
 * checkout lives and the CLI binary was placed by the installer.
 *
 * Inside a self-contained binary `base_path()` / `storage_path()` resolve into
 * the `phar://` stream and cannot be used to locate the host install directory.
 * The resolver instead reads `ORBIT_INSTALL_PATH` (the env var honoured by
 * `bin/install-orbit`) and falls back to `$HOME/orbit`, mirroring the
 * installer's `TARGET_DIR` default.
 */
final class CheckoutPathResolver
{
    public function resolve(): string
    {
        $override = getenv('ORBIT_INSTALL_PATH');

        if (is_string($override) && $override !== '') {
            return rtrim($override, '/');
        }

        $home = getenv('HOME');

        if (is_string($home) && $home !== '') {
            return rtrim($home, '/').'/orbit';
        }

        return '/home/orbit/orbit';
    }
}

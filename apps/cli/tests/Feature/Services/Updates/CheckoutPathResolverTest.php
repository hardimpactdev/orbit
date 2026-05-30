<?php

declare(strict_types=1);

use App\Services\Updates\CheckoutPathResolver;

describe('CheckoutPathResolver', function (): void {
    it('returns ORBIT_INSTALL_PATH when the env var is set', function (): void {
        $previous = getenv('ORBIT_INSTALL_PATH');
        putenv('ORBIT_INSTALL_PATH=/custom/install/path');

        try {
            $path = (new CheckoutPathResolver)->resolve();
        } finally {
            $previous === false ? putenv('ORBIT_INSTALL_PATH') : putenv("ORBIT_INSTALL_PATH={$previous}");
        }

        expect($path)->toBe('/custom/install/path');
    });

    it('strips trailing slashes from ORBIT_INSTALL_PATH', function (): void {
        $previous = getenv('ORBIT_INSTALL_PATH');
        putenv('ORBIT_INSTALL_PATH=/custom/install/path/');

        try {
            $path = (new CheckoutPathResolver)->resolve();
        } finally {
            $previous === false ? putenv('ORBIT_INSTALL_PATH') : putenv("ORBIT_INSTALL_PATH={$previous}");
        }

        expect($path)->toBe('/custom/install/path');
    });

    it('falls back to HOME/orbit when ORBIT_INSTALL_PATH is not set', function (): void {
        $previousInstall = getenv('ORBIT_INSTALL_PATH');
        $previousHome = getenv('HOME');

        putenv('ORBIT_INSTALL_PATH');
        putenv('HOME=/home/orbit-user');

        try {
            $path = (new CheckoutPathResolver)->resolve();
        } finally {
            $previousInstall === false ? putenv('ORBIT_INSTALL_PATH') : putenv("ORBIT_INSTALL_PATH={$previousInstall}");
            $previousHome === false ? putenv('HOME') : putenv("HOME={$previousHome}");
        }

        expect($path)->toBe('/home/orbit-user/orbit');
    });

    it('strips trailing slashes from HOME before appending orbit', function (): void {
        $previousInstall = getenv('ORBIT_INSTALL_PATH');
        $previousHome = getenv('HOME');

        putenv('ORBIT_INSTALL_PATH');
        putenv('HOME=/home/orbit-user/');

        try {
            $path = (new CheckoutPathResolver)->resolve();
        } finally {
            $previousInstall === false ? putenv('ORBIT_INSTALL_PATH') : putenv("ORBIT_INSTALL_PATH={$previousInstall}");
            $previousHome === false ? putenv('HOME') : putenv("HOME={$previousHome}");
        }

        expect($path)->toBe('/home/orbit-user/orbit');
    });

    it('does not resolve into phar:// or base_path()', function (): void {
        $previousInstall = getenv('ORBIT_INSTALL_PATH');
        putenv('ORBIT_INSTALL_PATH=/some/install/root');

        try {
            $path = (new CheckoutPathResolver)->resolve();
        } finally {
            $previousInstall === false ? putenv('ORBIT_INSTALL_PATH') : putenv("ORBIT_INSTALL_PATH={$previousInstall}");
        }

        expect($path)->not->toContain('phar://')
            ->and($path)->not->toStartWith(base_path());
    });
});

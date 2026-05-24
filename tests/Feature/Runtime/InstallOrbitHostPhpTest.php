<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

describe('install-orbit host PHP CLI baseline', function (): void {
    beforeEach(function (): void {
        $this->installer = File::get(base_path('bin/install-orbit'));
    });

    it('installs PHP 8.4 CLI and required extensions on Ubuntu through the Ondrej PPA', function (): void {
        expect($this->installer)
            ->toContain('software-properties-common')
            ->toContain('add-apt-repository ppa:ondrej/php -y')
            ->toContain('php8.4-cli')
            ->toContain('php8.4-mbstring')
            ->toContain('php8.4-curl')
            ->toContain('php8.4-sqlite3')
            ->toContain('php8.4-xml')
            ->toContain('update-alternatives --set php /usr/bin/php8.4');
    });

    it('verifies the host php command resolves to PHP 8.4 with the CLI executor extensions', function (): void {
        expect($this->installer)
            ->toContain('php --version')
            ->toContain('PHP 8.4.')
            ->toContain('pdo_sqlite')
            ->toContain('openssl')
            ->toContain('curl')
            ->toContain('mbstring')
            ->toContain('json')
            ->toContain('extension_loaded');
    });

    it('pins macOS to php at 8.4 when Homebrew exposes that formula', function (): void {
        expect($this->installer)
            ->toContain('brew search --formula php@8.4')
            ->toContain('brew install php@8.4')
            ->toContain('brew install php')
            ->toContain('brew link --overwrite --force php@8.4');
    });

    it('keeps Python and the standalone SQLite CLI out of Orbit helper prerequisites', function (): void {
        expect($this->installer)
            ->not->toContain('python3');

        expect(preg_match_all('/^\s+sqlite3\s+\\\\$/m', $this->installer))->toBe(0);
    });
});

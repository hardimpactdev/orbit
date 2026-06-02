<?php

declare(strict_types=1);

use App\Services\Tools\ToolCatalog;
use App\Tools\LaravelInstallerTool;
use Tests\TestCase;

uses(TestCase::class);

describe('LaravelInstallerTool', function (): void {
    it('has slug laravel-installer and category runtime', function (): void {
        $tool = new LaravelInstallerTool;

        expect($tool->slug())->toBe('laravel-installer')
            ->and($tool->category())->toBe('runtime');
    });

    it('declares install, update, and remove capabilities', function (): void {
        $tool = new LaravelInstallerTool;

        expect($tool->capabilities())->toContain('install')
            ->and($tool->capabilities())->toContain('update')
            ->and($tool->capabilities())->toContain('remove');
    });

    it('installScript runs composer global require laravel/installer', function (): void {
        $tool = new LaravelInstallerTool;

        expect($tool->installScript())->toContain('composer global require laravel/installer');
    });

    it('installScript symlinks the laravel binary into /usr/local/bin/laravel', function (): void {
        $tool = new LaravelInstallerTool;

        expect($tool->installScript())->toContain('/usr/local/bin/laravel');
    });

    it('installScript runs as the orbit system user', function (): void {
        $tool = new LaravelInstallerTool;

        expect($tool->installScript())->toContain('sudo -u orbit');
    });

    it('updateScript runs composer global update laravel/installer', function (): void {
        $tool = new LaravelInstallerTool;

        expect($tool->updateScript())->toContain('composer global update laravel/installer');
    });

    it('removeScript runs composer global remove laravel/installer', function (): void {
        $tool = new LaravelInstallerTool;

        expect($tool->removeScript())->toContain('composer global remove laravel/installer');
    });

    it('removeScript removes the /usr/local/bin/laravel symlink', function (): void {
        $tool = new LaravelInstallerTool;

        expect($tool->removeScript())->toContain('/usr/local/bin/laravel');
    });

    it('probeMetadata identifies the laravel binary', function (): void {
        $tool = new LaravelInstallerTool;
        $metadata = $tool->probeMetadata();

        expect($metadata['binary'])->toBe('laravel');
    });

    it('is resolvable by slug from the tool catalog', function (): void {
        $catalog = app(ToolCatalog::class);

        expect($catalog->definition('laravel-installer'))->toBeInstanceOf(LaravelInstallerTool::class);
    });
});

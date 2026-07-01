<?php

declare(strict_types=1);

use App\Services\Tools\ToolCatalog;
use App\Tools\GitTool;
use Tests\TestCase;

uses(TestCase::class);

describe('GitTool', function (): void {
    it('has the correct slug and category', function (): void {
        $tool = new GitTool;

        expect($tool->slug())->toBe('git')->and($tool->category())->toBe('runtime');
    });

    it('declares install, update, and safe-adopt capabilities', function (): void {
        $tool = new GitTool;

        expect($tool->capabilities())
            ->toContain('install')
            ->and($tool->capabilities())
            ->toContain('update')
            ->and($tool->capabilities())
            ->toContain('safe-adopt');
    });

    it('installScript installs git from the upstream stable apt source on Ubuntu hosts', function (): void {
        $tool = new GitTool;
        $script = $tool->installScript();

        expect($script)
            ->toContain('# orbit install git')
            ->and($script)
            ->toContain('set -e')
            ->and($script)
            ->toContain('apt-get')
            ->and($script)
            ->toContain('ppa:git-core/ppa')
            ->and($script)
            ->toContain('add-apt-repository')
            ->and($script)
            ->toContain('software-properties-common')
            ->and($script)
            ->toContain('install -y -qq git')
            ->and($script)
            ->not->toContain('brew')->and($script)
            ->not->toContain('linuxbrew');
    });

    it('updateScript upgrades git from the upstream stable apt source on Ubuntu hosts', function (): void {
        $tool = new GitTool;

        $script = $tool->updateScript();

        expect($script)
            ->toContain('apt-get')
            ->and($script)
            ->toContain('ppa:git-core/ppa')
            ->and($script)
            ->toContain('add-apt-repository')
            ->and($script)
            ->toContain('--only-upgrade')
            ->and($script)
            ->toContain('git')
            ->and($script)
            ->not->toContain('brew')->and($script)
            ->not->toContain('linuxbrew');
    });

    it('probeMetadata identifies the system git binary', function (): void {
        $tool = new GitTool;
        $metadata = $tool->probeMetadata();

        expect($metadata['binary'])
            ->toBe('git')
            ->and($metadata['version_command'])
            ->toBe('git --version')
            ->and($metadata['update_command'])
            ->toBe($tool->updateScript());
    });

    it('is resolvable by slug from the tool catalog', function (): void {
        $catalog = app(ToolCatalog::class);

        expect($catalog->definition('git'))->toBeInstanceOf(GitTool::class);
    });
});

<?php

use App\Actions\Install\Linux;
use App\Actions\Install\Mac;
use App\Actions\Install\Shared;
use App\Services\Install\InstallLinuxPipeline;
use App\Services\Install\InstallMacPipeline;

describe('InstallLinuxPipeline', function () {
    it('defines correct step count', function () {
        $pipeline = new InstallLinuxPipeline;
        $steps = $pipeline->steps();

        // PHP installation moved to bootstrap installer (install.sh)
        expect($steps)->toHaveCount(20);
    });

    it('starts with prerequisites check', function () {
        $pipeline = new InstallLinuxPipeline;
        $steps = $pipeline->steps();

        expect($steps[0]['action'])->toBe(Linux\CheckPrerequisites::class);
    });

    it('includes Docker installation for Linux', function () {
        $pipeline = new InstallLinuxPipeline;
        $actions = collect($pipeline->steps())->pluck('action');

        expect($actions)->toContain(Linux\InstallDocker::class);
        expect($actions)->not->toContain(Mac\InstallOrbStack::class);
    });

    it('includes all shared actions', function () {
        $pipeline = new InstallLinuxPipeline;
        $actions = collect($pipeline->steps())->pluck('action');

        expect($actions)->toContain(Shared\CreateDirectories::class);
        expect($actions)->toContain(Shared\CopyConfigurationFiles::class);
        expect($actions)->toContain(Shared\InstallWebApp::class);
        expect($actions)->toContain(Shared\GenerateCaddyfile::class);
        expect($actions)->toContain(Shared\CreateDockerNetwork::class);
        expect($actions)->toContain(Shared\StartServices::class);
    });

    it('ends with health check', function () {
        $pipeline = new InstallLinuxPipeline;
        $steps = $pipeline->steps();

        expect($steps[count($steps) - 1]['action'])->toBe(Shared\HealthCheck::class);
    });
});

describe('InstallMacPipeline', function () {
    it('defines correct step count', function () {
        $pipeline = new InstallMacPipeline;
        $steps = $pipeline->steps();

        // PHP and Homebrew installation moved to bootstrap installer (install.sh)
        expect($steps)->toHaveCount(20);
    });

    it('starts with prerequisites check', function () {
        $pipeline = new InstallMacPipeline;
        $steps = $pipeline->steps();

        expect($steps[0]['action'])->toBe(Mac\CheckPrerequisites::class);
    });

    it('does not include Homebrew or PHP installation (moved to bootstrap)', function () {
        $pipeline = new InstallMacPipeline;
        $actions = collect($pipeline->steps())->pluck('action');

        // These are now handled by install.sh bootstrap script
        expect($actions)->not->toContain(Mac\InstallHomebrew::class);
        expect($actions)->not->toContain(Mac\InstallPhp::class);
    });

    it('includes OrbStack for macOS instead of Docker', function () {
        $pipeline = new InstallMacPipeline;
        $actions = collect($pipeline->steps())->pluck('action');

        expect($actions)->toContain(Mac\InstallOrbStack::class);
        expect($actions)->not->toContain(Linux\InstallDocker::class);
    });

    it('includes all shared actions', function () {
        $pipeline = new InstallMacPipeline;
        $actions = collect($pipeline->steps())->pluck('action');

        expect($actions)->toContain(Shared\CreateDirectories::class);
        expect($actions)->toContain(Shared\CopyConfigurationFiles::class);
        expect($actions)->toContain(Shared\InstallWebApp::class);
        expect($actions)->toContain(Shared\GenerateCaddyfile::class);
        expect($actions)->toContain(Shared\CreateDockerNetwork::class);
        expect($actions)->toContain(Shared\StartServices::class);
    });

    it('ends with health check', function () {
        $pipeline = new InstallMacPipeline;
        $steps = $pipeline->steps();

        expect($steps[count($steps) - 1]['action'])->toBe(Shared\HealthCheck::class);
    });
});

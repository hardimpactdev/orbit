<?php

declare(strict_types=1);

namespace Tests\E2E\Support;

final readonly class E2ENodeProbe
{
    public static function assertOrbitInstalled(E2EInstance $instance): void
    {
        $install = $instance->exec('test -d /home/orbit/orbit && test -f /home/orbit/orbit/artisan');
        $version = $instance->exec("sudo -iu orbit bash -lc 'orbit --version | grep -F Orbit'");

        expect($install->successful())->toBeTrue($install->output().$install->errorOutput())
            ->and($version->successful())->toBeTrue($version->output().$version->errorOutput());
    }
}

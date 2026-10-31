<?php

declare(strict_types=1);

it('rejects an out of range ssh port before retargeting', function (): void {
    $this
        ->artisan('orbit:node-retarget', [
            'name' => 'app-dev',
            'host' => '198.51.100.25',
            '--ssh-port' => '65536',
        ])
        ->expectsOutput('Node retarget arguments are invalid.')
        ->assertExitCode(1);
});

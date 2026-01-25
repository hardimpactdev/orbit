<?php

it('shows error when not running as phar', function () {
    $this->artisan('upgrade')
        ->expectsOutputToContain('only available when running as a compiled PHAR')
        ->assertExitCode(1);
});

it('shows error in json format when not running as phar', function () {
    $this->artisan('upgrade --json')
        ->assertExitCode(1);
});

it('has check option in signature', function () {
    $command = $this->app->make(\App\Commands\UpgradeCommand::class);
    $definition = $command->getDefinition();

    expect($definition->hasOption('check'))->toBeTrue();
    expect($definition->hasOption('json'))->toBeTrue();
});

it('has correct description', function () {
    $command = $this->app->make(\App\Commands\UpgradeCommand::class);

    expect($command->getDescription())->toBe('Upgrade Orbit to the latest version');
});

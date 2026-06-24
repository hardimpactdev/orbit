<?php

declare(strict_types=1);

use App\Librarian\LiveCliSurface;

it('reads the public command surface from the Orbit CLI', function (): void {
    $commands = new LiveCliSurface()->publicCommands();
    $byName = [];

    foreach ($commands as $command) {
        $byName[$command->name] = $command;
    }

    expect($commands)
        ->not->toBeEmpty()->and($byName)->toHaveKey('tool:install')->and($byName['tool:install']->slug())->toBe(
            'tool-install',
        )->and($byName['tool:install']->arguments)->toBe(['tool'])->and($byName['tool:install']->options)->toContain(
            'json',
        )->and($byName['tool:install']->options)
        ->not->toContain('help')->and($byName['tool:install']->options)
        ->not->toContain('verbose')->and(array_keys($byName))
        ->not->toContain('list')->and(array_keys($byName))
        ->not->toContain('completion');
});

<?php

declare(strict_types=1);

use Orbit\Core\Progress\StreamedStepTree;
use Symfony\Component\Console\Output\BufferedOutput;

it('uses the canonical spinner frame order for active streamed steps', function (): void {
    $output = new BufferedOutput(decorated: false);
    $renderer = new StreamedStepTree($output);

    $renderer->tree('Creating Workspace', [
        [
            'key' => 'setup',
            'label' => 'Run workspace setup steps',
            'doneLabel' => 'Ran workspace setup steps',
        ],
    ]);

    $renderer->step('setup', 'start');
    $renderer->tick();

    preg_match_all('/[○◉]\s+Run workspace setup steps/u', $output->fetch(), $matches);

    expect(array_slice($matches[0], -2))->toBe([
        '○  Run workspace setup steps',
        '◉  Run workspace setup steps',
    ]);
});

it('renders progress messages for active streamed steps', function (): void {
    $output = new BufferedOutput(decorated: false);
    $renderer = new StreamedStepTree($output);

    $renderer->tree('Setting Up Workspace', [
        [
            'key' => 'setup',
            'label' => 'Run workspace setup steps',
            'doneLabel' => 'Ran workspace setup steps',
        ],
    ]);

    $renderer->step('setup', 'start');
    $renderer->step('setup', 'progress', 'Running setup step 1/2: composer install --no-interaction');

    expect($output->fetch())
        ->toContain('Run workspace setup steps')
        ->toContain('Running setup step 1/2: composer install --no-interaction');
});

it('settles a streamed step to done and renders the footer', function (): void {
    $output = new BufferedOutput(decorated: false);
    $renderer = new StreamedStepTree($output);

    $renderer->tree('Creating App', [
        ['key' => 'register', 'label' => 'Register app record', 'doneLabel' => 'Registered app record'],
    ]);

    $renderer->step('register', 'start');
    $renderer->step('register', 'done');
    $renderer->finish("App 'docs' created");

    expect($output->fetch())
        ->toContain('Registered app record')
        ->toContain("App 'docs' created");
});

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

    expect($output->fetch())->toContain('○  Run workspace setup steps');

    $renderer->tick();

    expect($output->fetch())->toBe('');

    usleep(350_000);
    $renderer->tick();

    expect($output->fetch())->toContain('◉  Run workspace setup steps');
});

it('paints the initial active frame immediately when a new step starts right after the previous one completes', function (): void {
    $output = new BufferedOutput(decorated: false);
    $renderer = new StreamedStepTree($output);

    $renderer->tree('Deploying', [
        ['key' => 'first', 'label' => 'Pull image', 'doneLabel' => 'Pulled image'],
        ['key' => 'second', 'label' => 'Start container', 'doneLabel' => 'Started container'],
    ]);

    $renderer->step('first', 'start');
    $output->fetch();

    usleep(100_000);
    $renderer->tick();

    $renderer->step('first', 'done');
    $output->fetch();

    $renderer->step('second', 'start');

    expect($output->fetch())->toContain('○  Start container');
});

it('throttles active streamed step alternation to about 300ms', function (): void {
    $output = new BufferedOutput(decorated: false);
    $renderer = new StreamedStepTree($output);

    $renderer->tree('Updating Orbit', [
        [
            'key' => 'check',
            'label' => 'Checking for updates',
            'doneLabel' => 'Checking for updates',
        ],
    ]);

    $renderer->step('check', 'start');

    expect($output->fetch())->toContain('○  Checking for updates');

    $renderer->tick();
    expect($output->fetch())->toBe('');

    usleep(100_000);
    $renderer->tick();
    expect($output->fetch())->toBe('');

    usleep(250_000);
    $renderer->tick();

    expect($output->fetch())->toContain('◉  Checking for updates');
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

it('animates active streamed steps while the parent process is blocked', function (): void {
    if (! function_exists('pcntl_fork') || ! function_exists('posix_kill') || ! function_exists('pcntl_signal') || ! function_exists('pcntl_async_signals')) {
        $this->markTestSkipped('pcntl_fork, posix_kill, pcntl_signal, and pcntl_async_signals are required to observe parent-process ticker callbacks.');
    }

    $output = new BufferedOutput(decorated: true);
    $renderer = new StreamedStepTree($output);

    $renderer->tree('Updating Orbit', [
        [
            'key' => 'check',
            'label' => 'Checking for updates',
            'doneLabel' => 'Checking for updates',
        ],
    ]);

    $renderer->step('check', 'start');

    $deadline = microtime(true) + 0.7;

    while (microtime(true) < $deadline) {
        usleep(50_000);
    }

    $renderer->step('check', 'done');
    $renderer->finish('Success');

    preg_match_all('/\e\[36m[○◉]\e\[39m/u', $output->fetch(), $matches);

    expect(array_values(array_unique($matches[0])))->toBe([
        "\e[36m○\e[39m",
        "\e[36m◉\e[39m",
    ]);
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

<?php

declare(strict_types=1);

use App\Services\Updates\LocalUpdateRunner;
use App\Services\Updates\UpdateHumanProgressRenderer;
use Orbit\Core\Progress\StreamedStepTree;
use Symfony\Component\Console\Output\BufferedOutput;

it('alternates the active checking row icon across quiet ticks while waiting for runner events', function (): void {
    $output = new BufferedOutput(decorated: true);
    $renderer = new UpdateHumanProgressRenderer($output);

    $renderer->begin();
    $renderer->recordStep(LocalUpdateRunner::STEP_CHECK, 'start', null);

    $treeProperty = new ReflectionProperty(UpdateHumanProgressRenderer::class, 'tree');
    $treeProperty->setAccessible(true);

    /** @var StreamedStepTree $streamedTree */
    $streamedTree = $treeProperty->getValue($renderer);

    $streamedTree->tick();
    $streamedTree->tick();

    preg_match_all('/\e\[36m[○◉]\e\[39m/u', $output->fetch(), $matches);

    expect(array_values(array_unique($matches[0])))->toBe([
        "\e[36m○\e[39m",
        "\e[36m◉\e[39m",
    ]);
});

it('renders the initial single-step tree with vertical spacers before runner events', function (): void {
    $output = new BufferedOutput(decorated: false);
    $renderer = new UpdateHumanProgressRenderer($output);

    $renderer->begin();

    expect($output->fetch())
        ->toContain('Updating Orbit')
        ->toContain('│')
        ->toContain('○  Checking for updates')
        ->toContain('Working...');
});

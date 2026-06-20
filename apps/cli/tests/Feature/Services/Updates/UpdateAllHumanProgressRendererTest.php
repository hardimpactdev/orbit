<?php

declare(strict_types=1);

use App\Services\Updates\UpdateAllHumanProgressRenderer;
use Orbit\Core\Progress\ProgressEventType;
use Symfony\Component\Console\Output\BufferedOutput;

it('alternates the active row icon across quiet ticks while waiting for stream events', function (): void {
    $output = new BufferedOutput(decorated: true);
    $renderer = new UpdateAllHumanProgressRenderer;

    $renderer->begin($output);
    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'check-updates',
        'status' => 'running',
        'message' => 'Checking',
    ]);

    $renderer->tick();
    $renderer->tick();

    preg_match_all('/\e\[36m[○◉]\e\[39m/u', $output->fetch(), $matches);

    expect(array_values(array_unique($matches[0])))->toBe([
        "\e[36m○\e[39m",
        "\e[36m◉\e[39m",
    ]);
});

it('does not emit ansi spinner noise in non-decorated output', function (): void {
    $output = new BufferedOutput(decorated: false);
    $renderer = new UpdateAllHumanProgressRenderer;

    $renderer->begin($output);
    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'check-updates',
        'status' => 'running',
        'message' => 'Checking',
    ]);

    $renderer->tick();
    $renderer->tick();

    expect($output->fetch())
        ->toContain('Checking for updates')
        ->not->toMatch('/\e\[/');
});

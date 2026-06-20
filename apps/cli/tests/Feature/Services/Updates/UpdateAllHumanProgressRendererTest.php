<?php

declare(strict_types=1);

use App\Services\Updates\UpdateAllHumanProgressRenderer;
use Orbit\Core\Progress\ProgressEventType;
use Symfony\Component\Console\Output\BufferedOutput;

it('keeps begin inactive until a check step is marked running', function (): void {
    $output = new BufferedOutput(decorated: true);
    $renderer = new UpdateAllHumanProgressRenderer;

    $renderer->begin($output);
    $renderer->tick();

    expect($output->fetch())->not->toMatch('/\e\[36m[○◉]\e\[39m/u');
});

it('alternates the active row icon across quiet ticks after the check step starts', function (): void {
    $output = new BufferedOutput(decorated: true);
    $renderer = new UpdateAllHumanProgressRenderer;

    $renderer->begin($output);
    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'check-updates',
        'status' => 'running',
    ]);
    $renderer->tick();
    $renderer->tick();

    preg_match_all('/\e\[36m[○◉]\e\[39m/u', $output->fetch(), $matches);

    expect(array_values(array_unique($matches[0])))->toBe([
        "\e[36m○\e[39m",
        "\e[36m◉\e[39m",
    ]);
});

it('keeps alternating the active row icon across quiet ticks while waiting for stream events', function (): void {
    $output = new BufferedOutput(decorated: true);
    $renderer = new UpdateAllHumanProgressRenderer;

    $renderer->begin($output);
    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'check-fleet-versions',
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

it('renders begin on non-decorated output with one check row and footer last', function (): void {
    $output = new BufferedOutput(decorated: false);
    $renderer = new UpdateAllHumanProgressRenderer;

    $renderer->begin($output);

    $text = rtrim($output->fetch(), "\n");
    $lines = explode("\n", $text);

    expect(substr_count($text, 'Checking for updates'))->toBe(1)
        ->and($lines[array_key_last($lines)])->toContain('Working...');
});

it('renders both check rows with vertical spacers before any gateway events', function (): void {
    $output = new BufferedOutput(decorated: false);
    $renderer = new UpdateAllHumanProgressRenderer;

    $renderer->begin($output);

    $text = $output->fetch();

    expect($text)
        ->toContain('Updating Orbit nodes')
        ->toContain('Checking for updates')
        ->toContain('Checking fleet versions')
        ->toContain('Working...')
        ->and(substr_count($text, '│'))->toBeGreaterThanOrEqual(3);
});

it('does not emit ansi spinner noise or duplicate rows in non-decorated output', function (): void {
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

    $text = $output->fetch();

    expect($text)->toContain('Checking for updates')
        ->and(substr_count($text, 'Checking for updates'))->toBe(1);

    expect($text)->not->toMatch('/\e\[/');
});

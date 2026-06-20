<?php

declare(strict_types=1);

use App\Services\Updates\UpdateAllHumanProgressRenderer;
use Orbit\Core\Progress\ProgressEventType;
use Symfony\Component\Console\Output\BufferedOutput;

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

it('aligns settled check-row status with node stage columns', function (): void {
    $output = new BufferedOutput(decorated: false);
    $renderer = new UpdateAllHumanProgressRenderer;

    $renderer->begin($output);
    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'check-updates',
        'status' => 'done',
        'message' => 'Done: latest version is 1.2.3',
    ]);
    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'check-fleet-versions',
        'status' => 'done',
        'message' => 'Done: 1 outdated node found',
    ]);
    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'gateway',
        'status' => 'running',
        'message' => 'Replacing cli binary',
    ]);
    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'workload.beast',
        'status' => 'running',
        'message' => 'Replacing cli binary',
    ]);
    $renderer->localNodeSubStep($output, 'replacing_cli_binary');

    $text = $output->fetch();

    $columns = [];

    foreach ([
        ['Done: latest version is 1.2.3', 'Done:'],
        ['Done: 1 outdated node found', 'Done:'],
        ['gateway', 'Replacing'],
        ['beast', 'Replacing'],
        ['local', 'Replacing'],
    ] as [$needle, $statusNeedle]) {
        $line = findRendererProgressLine($text, $needle, $statusNeedle);

        expect($line)->not->toBeNull();

        $columns[] = strpos($line, $statusNeedle);
    }

    expect(array_values(array_unique($columns)))->toHaveCount(1);
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

function findRendererProgressLine(string $text, string $needle, ?string $statusNeedle = null): ?string
{
    $found = null;

    foreach (explode("\n", $text) as $line) {
        if (! str_contains($line, $needle)) {
            continue;
        }

        if ($statusNeedle !== null && ! str_contains($line, $statusNeedle)) {
            continue;
        }

        $found = $line;
    }

    return $found;
}

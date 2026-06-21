<?php

declare(strict_types=1);

use App\Services\Updates\UpdateAllHumanProgressRenderer;
use Orbit\Core\Progress\ForkedFrameTicker;
use Orbit\Core\Progress\ProgressEventType;
use Orbit\Core\Progress\VirtualTerminalScreen;
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

it('renders both idle check rows with vertical spacers and a Working footer', function (): void {
    $output = new BufferedOutput(decorated: false);
    $renderer = new UpdateAllHumanProgressRenderer;

    $renderer->begin($output);

    $text = $output->fetch();

    expect($text)
        ->toContain('Updating Orbit nodes')
        ->toContain('Checking for updates')
        ->toContain('Checking fleet versions')
        ->toContain('Working...')
        ->and(assertProgressTreeSpacerContract($text))->toBeTrue();
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
        'update_targets' => ['gateway', 'local', 'beast'],
    ]);
    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'gateway',
        'status' => 'done',
        'message' => '',
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
        ['beast', 'Replacing'],
        ['local', 'Replacing'],
    ] as [$needle, $statusNeedle]) {
        $line = findRendererProgressLine($text, $needle, $statusNeedle);

        expect($line)->not->toBeNull();

        $columns[] = strpos($line, $statusNeedle);
    }

    expect(array_values(array_unique($columns)))->toHaveCount(1);
});

it('shows Checking on active check rows before they settle', function (): void {
    $output = new BufferedOutput(decorated: false);
    $renderer = new UpdateAllHumanProgressRenderer;

    $renderer->begin($output);
    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'check-updates',
        'status' => 'running',
        'message' => 'Checking',
    ]);

    $text = $output->fetch();

    expect($text)->toMatch('/Checking for updates\s+Checking\b/');
});

it('renders a vertical spacer before every row and before the footer', function (): void {
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
        'message' => 'Done: 2 outdated nodes found',
        'update_targets' => ['gateway', 'local', 'agent', 'beast'],
    ]);
    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'gateway',
        'status' => 'done',
        'message' => '',
    ]);
    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'workload.agent',
        'status' => 'done',
        'message' => 'Workload node agent updated',
    ]);
    $renderer->finishSuccess($output, '1.2.3');

    expect(assertProgressTreeSpacerContract($output->fetch()))->toBeTrue();
});

it('reveals gateway, local, and workload rows as Waiting when outdated nodes are found', function (): void {
    $output = new BufferedOutput(decorated: false);
    $renderer = new UpdateAllHumanProgressRenderer;

    $renderer->begin($output);
    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'check-fleet-versions',
        'status' => 'done',
        'message' => 'Done: 2 outdated nodes found',
        'update_targets' => ['gateway', 'local', 'beast', 'agent'],
    ]);

    $text = $output->fetch();

    expect($text)->toMatch('/local\s+Waiting\b/')
        ->and($text)->toMatch('/beast\s+Waiting\b/')
        ->and($text)->toMatch('/agent\s+Waiting\b/')
        ->and($text)->not->toMatch('/gateway\s+Downloading/');

    assertProgressTargetOrder($text, ['gateway', 'local', 'beast', 'agent']);

    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'gateway',
        'status' => 'running',
        'message' => 'Downloading 1.2.3 assets',
    ]);

    expect($output->fetch())->toMatch('/gateway\s+Downloading 1\.2\.3 assets/');
});

it('does not reveal node rows before outdated nodes are found', function (): void {
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
        'message' => 'Done: all nodes running on 1.2.3',
    ]);
    $renderer->finishSuccess($output, '1.2.3', allCurrent: true);

    $text = $output->fetch();

    expect($text)->not->toMatch('/\bgateway\b/')
        ->and($text)->not->toMatch('/\blocal\b/')
        ->and($text)->not->toMatch('/\bagent\b/');
});

it('reveals local as Waiting while the gateway row is active', function (): void {
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
        'message' => 'Done: 2 outdated nodes found',
        'update_targets' => ['gateway', 'local', 'agent', 'beast'],
    ]);
    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'gateway',
        'status' => 'running',
        'message' => 'Downloading 1.2.3 assets',
    ]);

    $text = $output->fetch();

    expect($text)->toMatch('/gateway\s+Downloading 1\.2\.3 assets/')
        ->and($text)->toMatch('/local\s+Waiting\b/')
        ->and($text)->toMatch('/agent\s+Waiting\b/')
        ->and($text)->toMatch('/beast\s+Waiting\b/');
});

it('fails the gateway row for a fleet lease conflict without revealing local waiting rows', function (): void {
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
        'message' => 'Done: 2 outdated nodes found',
    ]);
    $renderer->fleetLeaseConflictFailed($output, 'Failed: update:all is still being performed by ingress');
    $renderer->finishFailure($output);

    $text = $output->fetch();

    expect($text)->toMatch('/gateway\s+Failed: update:all is still being performed by ingress/')
        ->and($text)->not->toMatch('/local\s+Waiting/')
        ->and($text)->not->toMatch('/\bbeast\s+Waiting/')
        ->and($text)->toContain('Failed');
});

it('strips revealed fan-out rows when a fleet lease conflict fails after an outdated fleet check', function (): void {
    $output = new BufferedOutput(decorated: false);
    $renderer = new UpdateAllHumanProgressRenderer;

    $renderer->begin($output);
    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'check-fleet-versions',
        'status' => 'done',
        'message' => 'Done: 2 outdated nodes found',
        'update_targets' => ['gateway', 'local', 'beast'],
    ]);
    $renderer->fleetLeaseConflictFailed($output, 'Failed: update:all is still being performed by ingress');
    $renderer->finishFailure($output);

    $order = new ReflectionProperty(UpdateAllHumanProgressRenderer::class, 'order');
    $order->setAccessible(true);

    expect($order->getValue($renderer))->toBe(['check-updates', 'check-fleet-versions', 'gateway']);
});

it('clears revealed fan-out rows from a decorated fleet lease conflict screen', function (): void {
    $output = new BufferedOutput(decorated: true);
    $renderer = new UpdateAllHumanProgressRenderer;

    $renderer->begin($output);
    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'check-fleet-versions',
        'status' => 'done',
        'message' => 'Done: 2 outdated nodes found',
        'update_targets' => ['gateway', 'local', 'beast'],
    ]);
    $renderer->fleetLeaseConflictFailed($output, 'Failed: update:all is still being performed by ingress');
    $renderer->finishFailure($output);

    $screen = new VirtualTerminalScreen;
    $screen->feed($output->fetch());
    $text = implode("\n", $screen->lines());

    expect($text)->toContain('gateway')
        ->and($text)->toContain('Failed: update:all is still being performed by ingress')
        ->and($text)->not->toContain('local')
        ->and($text)->not->toContain('beast');
});

it('keeps local and workload rows on Waiting when the gateway row fails', function (): void {
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
        'message' => 'Done: 2 outdated nodes found',
        'update_targets' => ['gateway', 'local', 'beast'],
    ]);
    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'gateway',
        'status' => 'fail',
        'message' => 'Gateway health failed',
    ]);
    $renderer->finishFailure($output);

    $text = $output->fetch();

    expect($text)->toMatch('/gateway\s+Failed\b.*Gateway health failed/')
        ->and($text)->toMatch('/local\s+Waiting\b/')
        ->and($text)->toMatch('/beast\s+Waiting\b/')
        ->and($text)->not->toMatch('/beast\s+Downloading 1\.2\.3/');
});

it('can show multiple active fan-out rows with distinct sub-stages after gateway succeeds', function (): void {
    $output = new BufferedOutput(decorated: false);
    $renderer = new UpdateAllHumanProgressRenderer;

    $renderer->begin($output);
    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'check-fleet-versions',
        'status' => 'done',
        'message' => 'Done: 2 outdated nodes found',
        'update_targets' => ['gateway', 'local', 'agent', 'beast'],
    ]);
    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'gateway',
        'status' => 'done',
        'message' => '',
    ]);
    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'workload.agent',
        'status' => 'running',
        'message' => 'Downloading 1.2.3',
    ]);
    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'workload.beast',
        'status' => 'running',
        'message' => 'Replacing cli binary',
    ]);

    $text = $output->fetch();

    expect($text)->toMatch('/agent\s+Downloading 1\.2\.3/')
        ->and($text)->toMatch('/beast\s+Replacing cli binary/');
});

it('preserves payload workload order before gateway succeeds and after out-of-order completion', function (): void {
    $waitingOutput = new BufferedOutput(decorated: false);
    $waitingRenderer = new UpdateAllHumanProgressRenderer;

    $waitingRenderer->begin($waitingOutput);
    $waitingRenderer->applyEvent($waitingOutput, ProgressEventType::Step, [
        'key' => 'check-fleet-versions',
        'status' => 'done',
        'message' => 'Done: 2 outdated nodes found',
        'update_targets' => ['gateway', 'local', 'beast', 'agent'],
    ]);

    assertProgressTargetOrder($waitingOutput->fetch(), ['gateway', 'local', 'beast', 'agent']);

    $settledOutput = new BufferedOutput(decorated: false);
    $settledRenderer = new UpdateAllHumanProgressRenderer;

    $settledRenderer->begin($settledOutput);
    $settledRenderer->applyEvent($settledOutput, ProgressEventType::Step, [
        'key' => 'check-fleet-versions',
        'status' => 'done',
        'message' => 'Done: 2 outdated nodes found',
        'update_targets' => ['gateway', 'local', 'beast', 'agent'],
    ]);
    $settledRenderer->applyEvent($settledOutput, ProgressEventType::Step, [
        'key' => 'gateway',
        'status' => 'done',
        'message' => '',
    ]);
    $settledRenderer->applyEvent($settledOutput, ProgressEventType::Step, [
        'key' => 'workload.beast',
        'status' => 'done',
        'message' => 'Workload node beast updated',
    ]);
    $settledRenderer->applyEvent($settledOutput, ProgressEventType::Step, [
        'key' => 'workload.agent',
        'status' => 'done',
        'message' => 'Workload node agent updated',
    ]);
    $settledRenderer->finishSuccess($settledOutput, '1.2.3');

    assertProgressTargetOrder($settledOutput->fetch(), ['gateway', 'local', 'beast', 'agent']);
});

it('settles the gateway row with doctor issue counts without failing the footer', function (): void {
    $output = new BufferedOutput(decorated: false);
    $renderer = new UpdateAllHumanProgressRenderer;

    $renderer->begin($output);
    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'check-fleet-versions',
        'status' => 'done',
        'message' => 'Done: 1 outdated node found',
        'update_targets' => ['gateway', 'local', 'beast'],
    ]);
    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'gateway',
        'status' => 'done',
        'message' => 'Gateway updated (2 issues)',
    ]);
    $renderer->finishSuccess($output, '1.2.3');

    $text = $output->fetch();

    expect($text)->toMatch('/gateway\s+Done \(2 issues\)/')
        ->and($text)->toContain('Success: All nodes are running on version 1.2.3');
});

it('treats doctor issue counts as non-fatal row results with a success footer', function (): void {
    $output = new BufferedOutput(decorated: false);
    $renderer = new UpdateAllHumanProgressRenderer;

    $renderer->begin($output);
    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'check-fleet-versions',
        'status' => 'done',
        'message' => 'Done: 1 outdated node found',
        'update_targets' => ['gateway', 'local', 'beast'],
    ]);
    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'gateway',
        'status' => 'done',
        'message' => '',
    ]);
    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'workload.beast',
        'status' => 'done',
        'message' => 'Workload node beast updated (3 issues)',
    ]);
    $renderer->finishSuccess($output, '1.2.3');

    $text = $output->fetch();

    expect($text)->toMatch('/beast\s+Done \(3 issues\)/')
        ->and($text)->toContain('Success: All nodes are running on version 1.2.3');
});

it('throttles active row alternation to about 300ms on decorated output', function (): void {
    $output = new BufferedOutput(decorated: true);
    $renderer = new UpdateAllHumanProgressRenderer;

    $renderer->begin($output);
    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'check-updates',
        'status' => 'running',
        'message' => 'Checking',
    ]);
    stopUpdateAllHumanProgressTicker($renderer);

    expect(stripRendererAnsi($output->fetch()))->toContain('○  Checking for updates');

    $renderer->tick();
    expect(stripRendererAnsi($output->fetch()))->toBe('');

    usleep(100_000);
    $renderer->tick();
    expect(stripRendererAnsi($output->fetch()))->toBe('');

    usleep(250_000);
    $renderer->tick();

    expect(stripRendererAnsi($output->fetch()))->toContain('◉  Checking for updates');
});

it('alternates active row indicators from open to filled and back to open', function (): void {
    $output = new BufferedOutput(decorated: true);
    $renderer = new UpdateAllHumanProgressRenderer;

    $renderer->begin($output);
    $renderer->applyEvent($output, ProgressEventType::Step, [
        'key' => 'check-updates',
        'status' => 'running',
        'message' => 'Checking',
    ]);
    stopUpdateAllHumanProgressTicker($renderer);

    expect(stripRendererAnsi($output->fetch()))->toContain('○  Checking for updates');

    rewindUpdateAllHumanProgressCadence($renderer);
    $renderer->tick();
    expect(stripRendererAnsi($output->fetch()))->toContain('◉  Checking for updates');

    rewindUpdateAllHumanProgressCadence($renderer);
    $renderer->tick();
    expect(stripRendererAnsi($output->fetch()))->toContain('○  Checking for updates');
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
        ->and($text)->toMatch('/Checking for updates\s+Checking\b/');

    expect($text)->not->toMatch('/\e\[/');
});

function stopUpdateAllHumanProgressTicker(UpdateAllHumanProgressRenderer $renderer): void
{
    $method = new ReflectionMethod(UpdateAllHumanProgressRenderer::class, 'stopTicker');
    $method->setAccessible(true);
    $method->invoke($renderer);
}

function rewindUpdateAllHumanProgressCadence(UpdateAllHumanProgressRenderer $renderer): void
{
    $property = new ReflectionProperty(UpdateAllHumanProgressRenderer::class, 'lastFrameAtUs');
    $property->setAccessible(true);
    $property->setValue($renderer, ((int) (microtime(true) * 1_000_000)) - ForkedFrameTicker::DEFAULT_INTERVAL_US);
}

function stripRendererAnsi(string $text): string
{
    return preg_replace('/\e\[[0-9;]*m/', '', $text) ?? $text;
}

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

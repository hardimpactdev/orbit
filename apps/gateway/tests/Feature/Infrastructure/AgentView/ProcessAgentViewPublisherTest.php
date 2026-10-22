<?php

declare(strict_types=1);

use App\Infrastructure\AgentView\ProcessAgentViewPublisher;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;

function publisher_clock(): object
{
    return new class
    {
        public float $now = 100.0;
    };
}

/**
 * PHP code that blocks a child until the gate file named by `$argv[1]` exists. A child whose gate
 * never opens exits with code 3 after 30 seconds, so a publisher that waits for its children fails
 * the test instead of passing late.
 */
function publisher_gate(): string
{
    return '$until = time() + 30; while (! file_exists($argv[1])) { if (time() > $until) { exit(3); } usleep(5_000); }';
}

function publisher_gate_path(): string
{
    return sys_get_temp_dir().'/orbit-publish-gate-'.bin2hex(random_bytes(4));
}

describe('the agent view publisher', function (): void {
    it('starts a run for each lane with the queued work and never waits for them', function (): void {
        $gate = publisher_gate_path();
        $out = tempnam(sys_get_temp_dir(), 'publish');
        $clock = publisher_clock();
        $publisher = new ProcessAgentViewPublisher(
            command: [PHP_BINARY, '-r', publisher_gate().' file_put_contents($argv[2], json_encode(array_slice($argv, 3)).PHP_EOL, FILE_APPEND);', '--', $gate, $out],
            log: new NullLogger,
            clock: static fn (): float => $clock->now,
        );

        $publisher->queueWorkspaces(2, [5, 6]);
        $publisher->queueUsage(1_790_000_000);
        $publisher->poll();

        // The runs wait for the gate, so poll returned while both were still running.
        expect($publisher->isRunning())->toBeTrue()
            ->and(file_get_contents($out))->toBe('');

        touch($gate);

        while ($publisher->isRunning()) {
            usleep(20_000);
        }
        $publisher->poll();

        $runs = array_map(static fn (string $line): mixed => json_decode($line, true), file($out, FILE_IGNORE_NEW_LINES) ?: []);
        sort($runs);

        expect($runs)->toBe([['--usage=1790000000'], ['--workspace=2:5', '--workspace=2:6']])
            ->and($publisher->isRunning())->toBeFalse();
        unlink($out);
        unlink($gate);
    });

    it('keeps one run at a time, stops an overdue run, and backs off before the next', function (): void {
        $gate = publisher_gate_path();
        $finished = publisher_gate_path();
        $clock = publisher_clock();
        $publisher = new ProcessAgentViewPublisher(
            command: [PHP_BINARY, '-r', publisher_gate().' touch($argv[2]);', '--', $gate, $finished],
            log: new NullLogger,
            clock: static fn (): float => $clock->now,
        );

        $publisher->queueUsage(1);
        $publisher->poll();
        $publisher->queueUsage(2);
        $clock->now += 5;
        $publisher->poll();
        expect($publisher->isRunning())->toBeTrue();

        $clock->now += ProcessAgentViewPublisher::DeadlineSeconds;
        $publisher->poll();
        // The gate never opened, so the run ended because poll stopped it, not because it finished.
        expect($publisher->isRunning())->toBeFalse()
            ->and(file_exists($finished))->toBeFalse();

        $publisher->poll();
        expect($publisher->isRunning())->toBeFalse();

        $clock->now += ProcessAgentViewPublisher::BackoffSeconds;
        $publisher->poll();
        expect($publisher->isRunning())->toBeTrue();
        $publisher->stop();
        expect($publisher->isRunning())->toBeFalse();
    });

    it('puts the workspaces of a failed or stopped run back and runs them again after the backoff', function (string $code): void {
        $clock = publisher_clock();
        $publisher = new ProcessAgentViewPublisher(
            command: [PHP_BINARY, '-r', $code, '--'],
            log: new NullLogger,
            clock: static fn (): float => $clock->now,
        );

        $publisher->queueWorkspaces(2, [5]);
        $publisher->poll();
        expect($publisher->pendingWorkspaces())->toBe([]);

        $clock->now += ProcessAgentViewPublisher::DeadlineSeconds;
        $deadline = microtime(true) + 5;
        while ($publisher->pendingWorkspaces() === [] && microtime(true) < $deadline) {
            usleep(20_000);
            $publisher->poll();
        }

        expect($publisher->pendingWorkspaces())->toBe(['2:5'])
            ->and($publisher->isRunning())->toBeFalse();

        $publisher->queueWorkspaces(2, [6]);
        $publisher->poll();
        expect($publisher->isRunning())->toBeFalse();

        $clock->now += ProcessAgentViewPublisher::BackoffSeconds;
        $publisher->poll();
        expect($publisher->isRunning())->toBeTrue()
            ->and($publisher->pendingWorkspaces())->toBe([]);
        $publisher->stop();
    })->with([
        'failed' => ['exit(1);'],
        'stopped at the deadline' => ['sleep(30);'],
    ]);

    it('runs task workspaces while a usage run is still busy', function (): void {
        $out = tempnam(sys_get_temp_dir(), 'publish');
        $clock = publisher_clock();
        $publisher = new ProcessAgentViewPublisher(
            command: [PHP_BINARY, '-r', 'if (str_starts_with($argv[2], "--usage")) { sleep(30); } file_put_contents($argv[1], $argv[2]);', '--', $out],
            log: new NullLogger,
            clock: static fn (): float => $clock->now,
        );

        $publisher->queueUsage(1);
        $publisher->poll();
        $publisher->queueWorkspaces(2, [5]);
        $publisher->poll();

        $deadline = microtime(true) + 5;
        while (file_get_contents($out) === '' && microtime(true) < $deadline) {
            usleep(20_000);
        }

        expect(file_get_contents($out))->toBe('--workspace=2:5');
        $publisher->stop();
        unlink($out);
    });

    it('puts the workspaces back when a run cannot start', function (): void {
        $clock = publisher_clock();
        $log = new class extends AbstractLogger
        {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = "{$level}: {$message}";
            }
        };
        $publisher = new ProcessAgentViewPublisher(
            command: [PHP_BINARY, '-r', 'exit(0);', '--'],
            log: $log,
            clock: static fn (): float => $clock->now,
            workingDirectory: sys_get_temp_dir().'/orbit-no-such-directory-'.bin2hex(random_bytes(4)),
        );

        $publisher->queueWorkspaces(2, [5]);
        $publisher->poll();

        expect($publisher->isRunning())->toBeFalse()
            ->and($publisher->pendingWorkspaces())->toBe(['2:5'])
            ->and($publisher->failures())->toBe(['2:5' => 1])
            ->and($log->messages)->toContain('warning: The agent view publish run could not start.');
    });

    it('drops a workspace after repeated failures, logs it, and takes it again on its next change', function (): void {
        $clock = publisher_clock();
        $log = new class extends AbstractLogger
        {
            /** @var list<array{string, array<string, mixed>}> */
            public array $errors = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                if ($level === 'error') {
                    $this->errors[] = [(string) $message, $context];
                }
            }
        };
        $publisher = new ProcessAgentViewPublisher(
            command: [PHP_BINARY, '-r', 'exit(0);', '--'],
            log: $log,
            clock: static fn (): float => $clock->now,
            workingDirectory: sys_get_temp_dir().'/orbit-no-such-directory-'.bin2hex(random_bytes(4)),
        );

        $publisher->queueWorkspaces(2, [5]);

        foreach (range(1, ProcessAgentViewPublisher::MaxAttempts) as $attempt) {
            $publisher->poll();
            $clock->now += ProcessAgentViewPublisher::BackoffSeconds;
        }

        expect($publisher->pendingWorkspaces())->toBe([])
            ->and($publisher->failures())->toBe([])
            ->and($log->errors)->toHaveCount(1)
            ->and($log->errors[0][1])->toBe(['workspaces' => ['2:5'], 'attempts' => ProcessAgentViewPublisher::MaxAttempts]);

        $publisher->queueWorkspaces(2, [5]);
        expect($publisher->pendingWorkspaces())->toBe(['2:5']);
    });

    it('counts failures in a row: a successful run and a new change both start the count again', function (): void {
        $marker = sys_get_temp_dir().'/orbit-publish-marker-'.bin2hex(random_bytes(4));
        $clock = publisher_clock();
        $publisher = new ProcessAgentViewPublisher(
            // Fails the first run, then succeeds.
            command: [PHP_BINARY, '-r', 'if (! file_exists($argv[1])) { touch($argv[1]); exit(1); }', '--', $marker],
            log: new NullLogger,
            clock: static fn (): float => $clock->now,
        );
        $finish = static function () use ($publisher): void {
            $deadline = microtime(true) + 5;
            while ($publisher->isRunning() && microtime(true) < $deadline) {
                usleep(20_000);
            }
            $publisher->poll();
        };

        try {
            $publisher->queueWorkspaces(2, [5]);
            $publisher->poll();
            $finish();
            expect($publisher->failures())->toBe(['2:5' => 1]);

            $clock->now += ProcessAgentViewPublisher::BackoffSeconds;
            $publisher->poll();
            $finish();
            expect($publisher->failures())->toBe([])
                ->and($publisher->pendingWorkspaces())->toBe([]);

            unlink($marker);
            $publisher->queueWorkspaces(2, [5]);
            $clock->now += ProcessAgentViewPublisher::BackoffSeconds;
            $publisher->poll();
            $finish();
            expect($publisher->failures())->toBe(['2:5' => 1]);

            $publisher->queueWorkspaces(2, [5]);
            expect($publisher->failures())->toBe([]);
        } finally {
            @unlink($marker);
        }
    });

    it('relays log events in a separate lane, passes them in order on standard input, and never waits', function (): void {
        $gate = publisher_gate_path();
        $out = tempnam(sys_get_temp_dir(), 'publish');
        $clock = publisher_clock();
        $publisher = new ProcessAgentViewPublisher(
            command: [PHP_BINARY, '-r', 'if (($argv[3] ?? null) === "--logs") { '.publisher_gate().' file_put_contents($argv[2], stream_get_contents(STDIN)); echo json_encode(["open_streams" => 1]), PHP_EOL; }', '--', $gate, $out],
            log: new NullLogger,
            clock: static fn (): float => $clock->now,
        );
        $stream = str_repeat('ab', 16);

        $publisher->queueLog(3, 'client-log', ['stream' => $stream, 'sequence' => 1, 'lines' => ['one'], 'dropped' => 0, 'skipped' => 0]);
        $publisher->queueLog(3, 'client-log', ['stream' => $stream, 'sequence' => 2, 'lines' => [str_repeat('x', 300)], 'dropped' => 0, 'skipped' => 0]);
        $publisher->queueLog(3, 'client-log-end', ['stream' => $stream, 'reason' => 'source_unavailable']);
        $publisher->queueLog(3, 'client-heartbeat', ['stream' => $stream]);
        $publisher->poll();

        // The log run waits for the gate, so poll returned while it was still running.
        expect($publisher->isRunning())->toBeTrue()
            ->and(file_get_contents($out))->toBe('');

        touch($gate);

        while ($publisher->isRunning()) {
            usleep(20_000);
        }
        $publisher->poll();
        $batch = json_decode((string) file_get_contents($out), true);

        expect(array_column($batch['items'], 'type'))->toBe(['lines', 'end'])
            ->and($batch['items'][0]['lines'])->toBe(['one', str_repeat('x', 300)])
            ->and($batch['sweep'])->toBeTrue();
        unlink($out);
        unlink($gate);
    });

    it('puts a failed log run back and runs it again after the log backoff', function (): void {
        $marker = sys_get_temp_dir().'/orbit-publish-marker-'.bin2hex(random_bytes(4));
        $out = tempnam(sys_get_temp_dir(), 'publish');
        $clock = publisher_clock();
        $publisher = new ProcessAgentViewPublisher(
            // Fails the first run, then succeeds and records what it got.
            command: [PHP_BINARY, '-r', '$in = stream_get_contents(STDIN); if (! file_exists($argv[1])) { touch($argv[1]); exit(1); } file_put_contents($argv[2], $in); echo json_encode(["open_streams" => 0]), PHP_EOL;', '--', $marker, $out],
            log: new NullLogger,
            clock: static fn (): float => $clock->now,
        );
        $finish = static function () use ($publisher): void {
            $deadline = microtime(true) + 5;
            while ($publisher->isRunning() && microtime(true) < $deadline) {
                usleep(20_000);
            }
            $publisher->poll();
        };

        try {
            $publisher->queueLog(3, 'client-log', ['stream' => str_repeat('ab', 16), 'lines' => ['one'], 'dropped' => 0, 'skipped' => 0]);
            $publisher->poll();
            $finish();
            $publisher->queueLog(3, 'client-log', ['stream' => str_repeat('ab', 16), 'lines' => ['two'], 'dropped' => 0, 'skipped' => 0]);
            $publisher->poll();

            expect($publisher->isRunning())->toBeFalse();

            $clock->now += ProcessAgentViewPublisher::LogBackoffSeconds;
            $publisher->poll();
            $finish();
            $batch = json_decode((string) file_get_contents($out), true);

            expect(array_map(static fn (array $item): array => $item['lines'], $batch['items']))->toBe([['one'], ['two']])
                ->and($publisher->isRunning())->toBeFalse();
        } finally {
            @unlink($marker);
            @unlink($out);
        }
    });
});

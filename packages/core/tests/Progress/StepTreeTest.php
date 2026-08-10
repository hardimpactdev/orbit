<?php

declare(strict_types=1);

use Orbit\Core\Progress\StepTree;
use Symfony\Component\Console\Output\BufferedOutput;

it('runs each step in order and renders the success footer', function (): void {
    $output = new BufferedOutput;
    $calls = [];

    $result = new StepTree($output)->run(
        'Removing node \'app-1\'',
        [
            [
                'label' => 'Resolving target',
                'doneLabel' => 'Resolved target',
                'run' => function () use (&$calls): string {
                    $calls[] = 'resolve';

                    return 'app-1';
                },
            ],
            [
                'label' => 'Removing node from registry',
                'doneLabel' => 'Removed node from registry',
                'run' => function () use (&$calls): string {
                    $calls[] = 'remove';

                    return 'gateway updated';
                },
            ],
        ],
        doneFooter: "Successfully removed node 'app-1'.",
    );

    $text = $output->fetch();

    expect($result->isCompleted())
        ->toBeTrue()
        ->and($result->error)
        ->toBeNull()
        ->and($result->results)
        ->toBe(['app-1', 'gateway updated'])
        ->and($calls)
        ->toBe(['resolve', 'remove'])
        ->and($text)
        ->toContain("Removing node 'app-1'")
        ->and($text)
        ->toContain('Resolved target')
        ->and($text)
        ->toContain('Removed node from registry')
        ->and($text)
        ->toContain("Successfully removed node 'app-1'.");
});

it('stops at the first failing step and renders a failure footer', function (): void {
    $output = new BufferedOutput;
    $reached = [];

    $result = new StepTree($output)->run(
        'Removing node \'app-1\'',
        [
            [
                'label' => 'Resolving target',
                'doneLabel' => 'Resolved target',
                'run' => function () use (&$reached): string {
                    $reached[] = 'resolve';

                    return 'ok';
                },
            ],
            [
                'label' => 'Removing node from registry',
                'doneLabel' => 'Removed node from registry',
                'run' => function () use (&$reached): never {
                    $reached[] = 'remove';

                    throw new RuntimeException('node is the gateway');
                },
            ],
            [
                'label' => 'Refreshing topology',
                'run' => function () use (&$reached): string {
                    $reached[] = 'refresh';

                    return 'never';
                },
            ],
        ],
        doneFooter: 'done',
        failFooter: 'Could not remove node.',
    );

    $text = $output->fetch();

    expect($result->isCompleted())
        ->toBeFalse()
        ->and($result->error)
        ->toBeInstanceOf(RuntimeException::class)
        ->and($result->error?->getMessage())
        ->toBe('node is the gateway')
        ->and($reached)
        ->toBe(['resolve', 'remove'])
        ->and($text)
        ->toContain('Removing node from registry')
        ->and($text)
        ->toContain('node is the gateway')
        ->and($text)
        ->not
        ->toContain('Removed node from registry')
        ->and($text)
        ->toContain('Could not remove node.');
});

it('settles every phase of an atomic operation on success', function (): void {
    $output = new BufferedOutput;

    $result = new StepTree($output)->runOperation(
        'Removing node \'app-1\'',
        [
            ['label' => 'Validate removal', 'doneLabel' => 'Validated removal'],
            ['label' => 'Remove node grants', 'doneLabel' => 'Removed node grants'],
            ['label' => 'Remove node record', 'doneLabel' => 'Removed node record'],
        ],
        work: static fn (): string => 'removed',
        doneFooter: "Node 'app-1' removed",
    );

    $text = $output->fetch();

    expect($result->isCompleted())
        ->toBeTrue()
        ->and($result->results)
        ->toBe(['removed'])
        ->and($text)
        ->toContain('Validated removal')
        ->and($text)
        ->toContain('Removed node grants')
        ->and($text)
        ->toContain('Removed node record')
        ->and($text)
        ->toContain("Node 'app-1' removed");
});

it('marks no phase done when an atomic operation fails', function (): void {
    $output = new BufferedOutput;

    $result = new StepTree($output)->runOperation(
        'Removing node \'app-1\'',
        [
            ['label' => 'Validate removal', 'doneLabel' => 'Validated removal'],
            ['label' => 'Remove node record', 'doneLabel' => 'Removed node record'],
        ],
        work: static function (): never {
            throw new RuntimeException("Node 'app-1' not found.");
        },
        doneFooter: "Node 'app-1' removed",
    );

    $text = $output->fetch();

    expect($result->isCompleted())
        ->toBeFalse()
        ->and($result->error)
        ->toBeInstanceOf(RuntimeException::class)
        ->and($text)
        ->toContain("Node 'app-1' not found.")
        ->and($text)
        ->not->toContain('Validated removal')->and($text)
        ->not->toContain('Removed node record');
});

it('arms the spinner child stop handler before immediate work completes', function (): void {
    if (
        ! function_exists('pcntl_fork')
        || ! function_exists('pcntl_waitpid')
        || ! function_exists('posix_kill')
        || ! function_exists('pcntl_signal')
        || ! function_exists('pcntl_async_signals')
        || ! function_exists('pcntl_sigprocmask')
    ) {
        $this->markTestSkipped('pcntl signal masking is required to verify spinner startup.');
    }

    $sandboxPid = pcntl_fork();

    if ($sandboxPid === -1) {
        $this->markTestSkipped('pcntl_fork is required to verify spinner startup.');
    }

    if ($sandboxPid === 0) {
        $selfPid = getmypid();

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function () use ($selfPid): never {
            if (getmypid() !== $selfPid) {
                posix_kill($selfPid, SIGTERM);
            }

            exit(143);
        });

        for ($attempt = 0; $attempt < 64; $attempt++) {
            $output = new BufferedOutput(decorated: true);
            new StepTree($output)->runOperation(
                'Checking Orbit',
                [['label' => 'Check version']],
                work: static fn (): null => null,
                doneFooter: 'Checked Orbit',
            );
        }

        exit(0);
    }

    pcntl_waitpid($sandboxPid, $status);

    expect(pcntl_wifexited($status))->toBeTrue()->and(pcntl_wexitstatus($status))->toBe(0);
});

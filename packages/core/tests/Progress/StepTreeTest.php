<?php

declare(strict_types=1);

use Orbit\Core\Progress\StepTree;
use Symfony\Component\Console\Output\BufferedOutput;

it('runs each step in order and renders the success footer', function (): void {
    $output = new BufferedOutput;
    $calls = [];

    $result = (new StepTree($output))->run('Removing node \'app-1\'', [
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
    ], doneFooter: "Successfully removed node 'app-1'.");

    $text = $output->fetch();

    expect($result->isCompleted())->toBeTrue()
        ->and($result->error)->toBeNull()
        ->and($result->results)->toBe(['app-1', 'gateway updated'])
        ->and($calls)->toBe(['resolve', 'remove'])
        ->and($text)->toContain("Removing node 'app-1'")
        ->and($text)->toContain('Resolved target')
        ->and($text)->toContain('Removed node from registry')
        ->and($text)->toContain("Successfully removed node 'app-1'.");
});

it('stops at the first failing step and renders a failure footer', function (): void {
    $output = new BufferedOutput;
    $reached = [];

    $result = (new StepTree($output))->run('Removing node \'app-1\'', [
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
    ], doneFooter: 'done', failFooter: 'Could not remove node.');

    $text = $output->fetch();

    expect($result->isCompleted())->toBeFalse()
        ->and($result->error)->toBeInstanceOf(RuntimeException::class)
        ->and($result->error?->getMessage())->toBe('node is the gateway')
        ->and($reached)->toBe(['resolve', 'remove'])
        ->and($text)->toContain('Could not remove node.');
});

it('settles every phase of an atomic operation on success', function (): void {
    $output = new BufferedOutput;

    $result = (new StepTree($output))->runOperation('Removing node \'app-1\'', [
        ['label' => 'Validate removal', 'doneLabel' => 'Validated removal'],
        ['label' => 'Remove node grants', 'doneLabel' => 'Removed node grants'],
        ['label' => 'Remove node record', 'doneLabel' => 'Removed node record'],
    ], work: static fn (): string => 'removed', doneFooter: "Node 'app-1' removed");

    $text = $output->fetch();

    expect($result->isCompleted())->toBeTrue()
        ->and($result->results)->toBe(['removed'])
        ->and($text)->toContain('Validated removal')
        ->and($text)->toContain('Removed node grants')
        ->and($text)->toContain('Removed node record')
        ->and($text)->toContain("Node 'app-1' removed");
});

it('marks no phase done when an atomic operation fails', function (): void {
    $output = new BufferedOutput;

    $result = (new StepTree($output))->runOperation('Removing node \'app-1\'', [
        ['label' => 'Validate removal', 'doneLabel' => 'Validated removal'],
        ['label' => 'Remove node record', 'doneLabel' => 'Removed node record'],
    ], work: static function (): never {
        throw new RuntimeException("Node 'app-1' not found.");
    }, doneFooter: "Node 'app-1' removed");

    $text = $output->fetch();

    expect($result->isCompleted())->toBeFalse()
        ->and($result->error)->toBeInstanceOf(RuntimeException::class)
        ->and($text)->toContain("Node 'app-1' not found.")
        ->and($text)->not->toContain('Validated removal')
        ->and($text)->not->toContain('Removed node record');
});

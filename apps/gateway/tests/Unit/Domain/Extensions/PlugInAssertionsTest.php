<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use PHPUnit\Framework\ExpectationFailedException;

final readonly class PlugInAssertionsConsumer
{
    /** @param iterable<object> $contributions */
    public function __construct(private iterable $contributions) {}
}

final readonly class PlugInAssertionsFirst {}

final readonly class PlugInAssertionsSecond {}

it('resolves the consumer binding and checks its actual private contributed list', function (): void {
    $container = new Container;
    $consumer = new PlugInAssertionsConsumer([new PlugInAssertionsFirst, new PlugInAssertionsSecond]);
    $container->bind(PlugInAssertionsConsumer::class, fn (): PlugInAssertionsConsumer => $consumer);

    expect(assertPlugInContributions(PlugInAssertionsConsumer::class, 'contributions', [PlugInAssertionsFirst::class, PlugInAssertionsSecond::class], $container))->toBe($consumer);
});

it('supports an empty contribution list', function (): void {
    $container = new Container;
    $container->instance(PlugInAssertionsConsumer::class, new PlugInAssertionsConsumer([]));

    assertPlugInContributions(PlugInAssertionsConsumer::class, 'contributions', [], $container);
});

it('supports an iterable production list', function (): void {
    $container = new Container;
    $container->instance(PlugInAssertionsConsumer::class, new PlugInAssertionsConsumer(new ArrayIterator([new PlugInAssertionsFirst])));

    assertPlugInContributions(PlugInAssertionsConsumer::class, 'contributions', [PlugInAssertionsFirst::class], $container);
});

it('fails when production wiring drops, repeats, reorders or substitutes contributions', function (array $actual): void {
    $container = new Container;
    $container->instance(PlugInAssertionsConsumer::class, new PlugInAssertionsConsumer($actual));

    expect(fn (): object => assertPlugInContributions(PlugInAssertionsConsumer::class, 'contributions', [PlugInAssertionsFirst::class, PlugInAssertionsSecond::class], $container))
        ->toThrow(ExpectationFailedException::class);
})->with([
    'dropped' => [[new PlugInAssertionsFirst]],
    'repeated' => [[new PlugInAssertionsFirst, new PlugInAssertionsFirst, new PlugInAssertionsSecond]],
    'reordered' => [[new PlugInAssertionsSecond, new PlugInAssertionsFirst]],
    'substituted' => [[new PlugInAssertionsFirst, new stdClass]],
]);

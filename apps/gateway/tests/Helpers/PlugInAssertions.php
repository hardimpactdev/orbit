<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\Container;
use PHPUnit\Framework\Assert;

/**
 * Inspect the real consumer's list, not a second resolution of the tag. No production accessor is needed.
 *
 * @template T of object
 *
 * @param  class-string<T>  $binding
 * @param  list<class-string>  $expected  Exact classes, including their production order.
 * @return T
 */
function assertPlugInContributions(string $binding, string $property, array $expected, ?Container $container = null): object
{
    $consumer = ($container ?? app())->make($binding);
    $contributions = new ReflectionProperty($consumer, $property)->getValue($consumer);
    Assert::assertIsIterable($contributions);
    $classes = [];

    foreach ($contributions as $contribution) {
        Assert::assertIsObject($contribution);
        $classes[] = $contribution::class;
    }

    expect($classes)->toBe($expected);

    return $consumer;
}

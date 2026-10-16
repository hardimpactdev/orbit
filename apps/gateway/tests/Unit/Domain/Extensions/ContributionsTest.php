<?php

declare(strict_types=1);

use App\Domain\Extensions\Contributions;
use Illuminate\Container\Container;

interface ContributionsTestPoint {}

final class ContributionsTestContribution implements ContributionsTestPoint {}

final class ContributionsTestStranger {}

it('refuses a tagged class that does not implement the plug-in point', function (): void {
    $container = new Container;
    $container->tag([ContributionsTestContribution::class, ContributionsTestStranger::class], ContributionsTestPoint::class);

    expect(fn (): array => new Contributions($container)->of(ContributionsTestPoint::class))
        ->toThrow(LogicException::class, 'The contribution [ContributionsTestStranger] to the plug-in point [ContributionsTestPoint] does not implement it.');
});

it('refuses a class tagged twice for one plug-in point', function (): void {
    $container = new Container;
    $container->tag([ContributionsTestContribution::class], ContributionsTestPoint::class);
    $container->tag([ContributionsTestContribution::class], ContributionsTestPoint::class);

    expect(fn (): array => new Contributions($container)->of(ContributionsTestPoint::class))
        ->toThrow(LogicException::class, 'The contribution [ContributionsTestContribution] to the plug-in point [ContributionsTestPoint] is tagged twice.');
});

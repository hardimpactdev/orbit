<?php

declare(strict_types=1);

namespace App\Domain\Extensions;

use Illuminate\Contracts\Container\Container;
use LogicException;

/** Reads what extensions contributed to a core plug-in point ([Extensions](/reference/extensions#plug-in-points)). */
final readonly class Contributions
{
    public function __construct(private Container $container) {}

    /**
     * Every implementation tagged with the plug-in interface, in registration order.
     *
     * A tagged class that does not implement the interface, or a class tagged twice, is a wiring error. It throws, so a
     * point never drops or repeats a contribution unseen.
     *
     * @template TPoint of object
     *
     * @param  class-string<TPoint>  $point
     * @return list<TPoint>
     *
     * @throws LogicException
     */
    public function of(string $point): array
    {
        $contributions = [];
        foreach ($this->container->tagged($point) as $contribution) {
            if (! $contribution instanceof $point) {
                throw new LogicException('The contribution ['.get_debug_type($contribution)."] to the plug-in point [{$point}] does not implement it.");
            }
            if (array_key_exists($contribution::class, $contributions)) {
                throw new LogicException('The contribution ['.$contribution::class."] to the plug-in point [{$point}] is tagged twice.");
            }
            $contributions[$contribution::class] = $contribution;
        }

        return array_values($contributions);
    }
}

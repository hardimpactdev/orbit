<?php

declare(strict_types=1);

namespace App\Domain\Extensions;

use Illuminate\Contracts\Container\Container;

/** Reads what extensions contributed to a core plug-in point ([Extensions](/reference/extensions#plug-in-points)). */
final readonly class Contributions
{
    public function __construct(private Container $container) {}

    /**
     * Every implementation tagged with the plug-in interface, in registration order.
     *
     * @template TPoint of object
     *
     * @param  class-string<TPoint>  $point
     * @return list<TPoint>
     */
    public function of(string $point): array
    {
        $contributions = [];
        foreach ($this->container->tagged($point) as $contribution) {
            if ($contribution instanceof $point) {
                $contributions[] = $contribution;
            }
        }

        return $contributions;
    }
}

<?php

declare(strict_types=1);

namespace App\Support;

/** The nearest-rank percentile of a list of integers. */
final class Percentile
{
    /** @param  list<int>  $values */
    public static function of(array $values, float $percentile): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);

        return $values[max(0, (int) ceil($percentile * count($values)) - 1)];
    }
}

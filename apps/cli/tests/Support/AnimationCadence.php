<?php

declare(strict_types=1);

namespace Tests\Support;

final class AnimationCadence
{
    /** @param list<float> $changes */
    public static function isReadable(array $changes): bool
    {
        if (count($changes) < 3) {
            return false;
        }

        for ($index = 1; $index < count($changes); $index++) {
            $interval = $changes[$index] - $changes[$index - 1];

            if ($interval < 0.18 || $interval > 0.65) {
                return false;
            }
        }

        return true;
    }
}

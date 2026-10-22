<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Console\Animation;

final class AnimationCadence
{
    /** @param list<float> $changes */
    public static function isReadable(array $changes, float $intervalSeconds = Animation::FRAME_INTERVAL_SECONDS): bool
    {
        if (count($changes) < 3) {
            return false;
        }

        $scale = Animation::FRAME_INTERVAL_SECONDS / $intervalSeconds;

        for ($index = 1; $index < count($changes); $index++) {
            $interval = ($changes[$index] - $changes[$index - 1]) * $scale;

            if ($interval < 0.18 || $interval > 0.65) {
                return false;
            }
        }

        return true;
    }
}

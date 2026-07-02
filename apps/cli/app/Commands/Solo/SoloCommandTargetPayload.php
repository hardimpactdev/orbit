<?php

declare(strict_types=1);

namespace App\Commands\Solo;

final class SoloCommandTargetPayload
{
    /**
     * @return array<string, mixed>
     */
    public function forNode(?string $node): array
    {
        return $node === null ? [] : ['node' => $node];
    }
}

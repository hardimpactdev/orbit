<?php

declare(strict_types=1);

namespace App\Commands\Solo;

use App\Services\OrbitConfigStore;

final class SoloCommandTargetPayload
{
    /**
     * @return array<string, mixed>
     */
    public function forNode(?string $node): array
    {
        if ($node !== null) {
            return ['node' => $node];
        }

        $defaultNode = app(OrbitConfigStore::class)->defaultNode();

        return $defaultNode === null ? ['self' => true] : ['node' => $defaultNode];
    }
}

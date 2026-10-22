<?php

declare(strict_types=1);

use Tests\Support\AnimationCadence;

describe('animation composition cadence', function (): void {
    it('rejects the observed fast resume interval while accepting a readable phase', function (): void {
        expect(AnimationCadence::isReadable([0.0, 0.3, 0.6, 0.9]))->toBeTrue()
            ->and(AnimationCadence::isReadable([0.0, 0.3, 0.3664124, 0.6664124]))->toBeFalse()
            ->and(AnimationCadence::isReadable([0.0, 0.0412849, 0.3412849]))->toBeFalse()
            ->and(AnimationCadence::isReadable([0.0, 0.3, 1.0]))->toBeFalse();
    });

    it('keeps the same relative cadence bounds with a short renderer interval', function (): void {
        $interval = 0.05;
        $scale = $interval / 0.3;
        $scaled = static fn (array $changes): array => array_map(static fn (float $time): float => $time * $scale, $changes);

        expect(AnimationCadence::isReadable($scaled([0.0, 0.3, 0.6, 0.9]), $interval))->toBeTrue()
            ->and(AnimationCadence::isReadable($scaled([0.0, 0.3, 0.3664124, 0.6664124]), $interval))->toBeFalse()
            ->and(AnimationCadence::isReadable($scaled([0.0, 0.0412849, 0.3412849]), $interval))->toBeFalse()
            ->and(AnimationCadence::isReadable($scaled([0.0, 0.3, 1.0]), $interval))->toBeFalse();
    });
});

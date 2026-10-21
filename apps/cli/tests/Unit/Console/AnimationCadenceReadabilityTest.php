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
});

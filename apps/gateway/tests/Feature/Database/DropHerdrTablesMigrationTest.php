<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

it('leaves no Herdr tables after migration', function (): void {
    expect(Schema::hasTable('herdr_sessions'))->toBeFalse()
        ->and(Schema::hasTable('herdr_observation_nonces'))->toBeFalse()
        ->and(Schema::hasTable('jwks_keys'))->toBeFalse();
});

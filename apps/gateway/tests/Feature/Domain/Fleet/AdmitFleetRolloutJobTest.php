<?php

declare(strict_types=1);

use App\Jobs\Fleet\AdmitFleetRollout;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('admits nothing and ends quietly when no rollout is ready', function (): void {
    config(['fleet.rollout' => false]);

    app()->call([new AdmitFleetRollout, 'handle']);

    expect(Operation::query()->where('kind', 'fleet.rollout')->exists())->toBeFalse();
});

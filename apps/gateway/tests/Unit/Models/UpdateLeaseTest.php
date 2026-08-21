<?php

declare(strict_types=1);

use App\Models\UpdateLease;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class);

it('reports whether the lease row is active', function (
    ?string $activeResourceKey,
    ?Carbon $releasedAt,
    bool $expected,
): void {
    $lease = new UpdateLease([
        'active_resource_key' => $activeResourceKey,
        'released_at' => $releasedAt,
    ]);

    expect($lease->isActive())->toBe($expected);
})->with([
    'active resource without release' => ['node:worker-01', null, true],
    'missing active resource' => [null, null, false],
    'released resource' => ['node:worker-01', Carbon::parse('2026-08-21 10:00:00'), false],
]);

it('compares owner tokens through the lease owner predicate', function (): void {
    $knownOwner = hash(algo: 'sha256', data: 'known-owner');
    $differentOwner = hash(algo: 'sha256', data: 'different-owner');
    $lease = new UpdateLease(['owner_token' => $knownOwner]);

    expect($lease->isOwnedBy($knownOwner))
        ->toBeTrue()
        ->and($lease->isOwnedBy($differentOwner))
        ->toBeFalse();
});

it('deactivates lease state without persistence', function (): void {
    $releasedAt = Carbon::parse('2026-08-21 10:00:00');
    $lease = new UpdateLease([
        'active_resource_key' => 'node:worker-01',
        'released_at' => null,
    ]);

    $lease->deactivate($releasedAt);

    expect($lease->isActive())
        ->toBeFalse()
        ->and($lease->active_resource_key)
        ->toBeNull()
        ->and($lease->released_at?->toIso8601String())
        ->toBe('2026-08-21T10:00:00+00:00')
        ->and($lease->exists)
        ->toBeFalse();
});

it('preserves the first release time when deactivated again', function (): void {
    $firstRelease = Carbon::parse('2026-08-21 10:00:00');
    $lease = new UpdateLease([
        'active_resource_key' => null,
        'released_at' => $firstRelease,
    ]);

    $lease->deactivate(Carbon::parse('2026-08-21 10:05:00'));

    expect($lease->released_at?->toIso8601String())
        ->toBe('2026-08-21T10:00:00+00:00');
});

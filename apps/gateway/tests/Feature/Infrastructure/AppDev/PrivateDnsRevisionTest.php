<?php

declare(strict_types=1);

use App\Infrastructure\AppDev\PrivateDnsRevision;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

describe('PrivateDnsRevision', function (): void {
    it('always hands out a higher revision than the one before', function (): void {
        Carbon::setTestNow(Carbon::parse('2026-11-07 12:00:00'));

        $first = (int) new PrivateDnsRevision()->next();
        $second = (int) new PrivateDnsRevision()->next();

        expect($second)->toBeGreaterThan($first)->and($first)->toBeGreaterThan(1_700_000_000_000_000);
    });

    it('does not go below the stored revision when the clock is behind it', function (): void {
        Carbon::setTestNow(Carbon::parse('2026-11-07 12:00:00'));
        DB::table('private_dns_revision')->insert(['id' => 1, 'revision' => 9_000_000_000_000_000]);

        expect(new PrivateDnsRevision()->next())->toBe('9000000000000001');
    });

    it('moves forward with the clock after a restore of an older database', function (): void {
        DB::table('private_dns_revision')->insert(['id' => 1, 'revision' => 1_000_000_000_000_000]);
        Carbon::setTestNow(Carbon::parse('2026-11-07 12:00:00'));

        expect((int) new PrivateDnsRevision()->next())->toBeGreaterThan(1_000_000_000_000_001);
    });
});

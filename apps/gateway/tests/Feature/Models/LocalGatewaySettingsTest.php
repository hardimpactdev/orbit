<?php

declare(strict_types=1);

use App\Models\LocalGatewaySettings;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('LocalGatewaySettings', function (): void {
    it('creates the canonical singleton row via current() when none exist', function (): void {
        expect(LocalGatewaySettings::query()->count())->toBe(0);

        $settings = LocalGatewaySettings::current();

        expect($settings)
            ->toBeInstanceOf(LocalGatewaySettings::class)
            ->and($settings->exists)
            ->toBeTrue()
            ->and($settings->singleton_key)
            ->toBe(LocalGatewaySettings::SINGLETON_KEY)
            ->and(LocalGatewaySettings::query()->count())
            ->toBe(1);
    });

    it('returns the existing canonical row on subsequent current() calls', function (): void {
        $first = LocalGatewaySettings::current();
        $first->update(['gateway_url' => 'https://10.6.0.2']);

        $second = LocalGatewaySettings::current();

        expect($second->id)
            ->toBe($first->id)
            ->and($second->gateway_url)
            ->toBe('https://10.6.0.2')
            ->and($second->singleton_key)
            ->toBe(LocalGatewaySettings::SINGLETON_KEY)
            ->and(LocalGatewaySettings::query()->count())
            ->toBe(1);
    });

    it('keeps a second current() init idempotent when the canonical row already exists', function (): void {
        LocalGatewaySettings::current();
        LocalGatewaySettings::current();

        expect(LocalGatewaySettings::query()->count())->toBe(1);
    });

    it('rejects a second local gateway settings row via the singleton unique constraint', function (): void {
        LocalGatewaySettings::current();

        expect(fn () => LocalGatewaySettings::query()->create([]))
            ->toThrow(UniqueConstraintViolationException::class);

        expect(LocalGatewaySettings::query()->count())->toBe(1);
    });

    it('has correct fillable identity and trust fields', function (): void {
        $settings = LocalGatewaySettings::current();
        $settings->update([
            'gateway_url' => 'https://10.6.0.2',
            'gateway_wg_ip' => '10.6.0.2',
            'ca_sha256' => 'aabbccdd',
            'ca_pem_path' => '/path/to/ca.pem',
            'trusted_at' => now(),
        ]);

        $settings->refresh();

        expect($settings->getFillable())
            ->not
            ->toContain('singleton_key')
            ->and($settings->gateway_url)
            ->toBe('https://10.6.0.2')
            ->and($settings->gateway_wg_ip)
            ->toBe('10.6.0.2')
            ->and($settings->ca_sha256)
            ->toBe('aabbccdd')
            ->and($settings->ca_pem_path)
            ->toBe('/path/to/ca.pem')
            ->and($settings->trusted_at)
            ->toBeInstanceOf(Carbon::class)
            ->and($settings->singleton_key)
            ->toBe(LocalGatewaySettings::SINGLETON_KEY);
    });

    it('cannot mint a non-default singleton_key via create', function (): void {
        expect(LocalGatewaySettings::query()->count())->toBe(0);

        $settings = LocalGatewaySettings::query()->create([
            'singleton_key' => 'other',
            'gateway_url' => 'https://example.test',
        ]);

        expect($settings->singleton_key)
            ->toBe(LocalGatewaySettings::SINGLETON_KEY)
            ->and(LocalGatewaySettings::query()->pluck('singleton_key')->all())
            ->toBe([LocalGatewaySettings::SINGLETON_KEY])
            ->and(LocalGatewaySettings::current()->id)
            ->toBe($settings->id);
    });

    it('cannot change singleton_key via fill or forceFill save', function (): void {
        $settings = LocalGatewaySettings::current();

        $settings->fill(['singleton_key' => 'other', 'gateway_url' => 'https://filled.test']);
        $settings->save();
        $settings->refresh();

        expect($settings->singleton_key)
            ->toBe(LocalGatewaySettings::SINGLETON_KEY)
            ->and($settings->gateway_url)
            ->toBe('https://filled.test');

        $settings->forceFill(['singleton_key' => 'other']);
        $settings->save();
        $settings->refresh();

        expect($settings->singleton_key)
            ->toBe(LocalGatewaySettings::SINGLETON_KEY)
            ->and(LocalGatewaySettings::query()->count())
            ->toBe(1);
    });

    it('does not include local_node_role field', function (): void {
        $settings = LocalGatewaySettings::current();

        expect($settings->getAttributes())->not->toHaveKey('local_node_role');
    });
});

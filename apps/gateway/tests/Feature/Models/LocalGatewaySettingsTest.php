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

        expect($settings->gateway_url)
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

    it('does not include local_node_role field', function (): void {
        $settings = LocalGatewaySettings::current();

        expect($settings->getAttributes())->not->toHaveKey('local_node_role');
    });
});
